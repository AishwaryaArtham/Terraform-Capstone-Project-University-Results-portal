import json
import subprocess
import os
import secrets
import grp
import tempfile
import time
import string

REGION = "us-east-1"
DB_INSTANCE = "terraform-university-results-db"
APP_SECRET = "terraform-wordpress-db-credentials"


def aws(*args):
    """Execute an AWS CLI command and return its output."""
    return subprocess.check_output(
        ["aws", *args, "--region", REGION, "--output", "text"],
        text=True
    ).strip()


def get_database_details():
    """Retrieve the RDS endpoint and master secret ARN."""
    response = aws(
        "rds", "describe-db-instances",
        "--db-instance-identifier", DB_INSTANCE,
        "--query", "DBInstances[0].[Endpoint.Address,MasterUserSecret.SecretArn]"
    )

    endpoint, master_secret_arn = response.split("\t")

    return endpoint, master_secret_arn

def get_master_credentials(master_secret_arn):
    """Retrieve RDS master credentials from Secrets Manager."""
    secret_json = aws(
        "secretsmanager", "get-secret-value",
        "--secret-id", master_secret_arn,
        "--query", "SecretString"
    )

    credentials = json.loads(secret_json)

    if not credentials.get("username") or not credentials.get("password"):
        raise RuntimeError("RDS master credentials are missing.")

    return credentials["username"], credentials["password"]

def get_or_create_app_password():
    """Reuse an existing password or generate a new one."""

    result = subprocess.run(
        [
            "aws", "secretsmanager", "get-secret-value",
            "--secret-id", APP_SECRET,
            "--query", "SecretString",
            "--region", REGION,
            "--output", "text",
        ],
        capture_output=True,
        text=True,
    )

    if result.returncode == 0:
        password = result.stdout.strip()

        if not password:
            raise RuntimeError("Existing WordPress secret is empty.")

        print("Existing WordPress password retrieved.")
        return password, False

    # Check whether the secret has no value yet.
    # Other AWS errors must not trigger password generation.
    check = subprocess.run(
        [
            "aws", "secretsmanager", "describe-secret",
            "--secret-id", APP_SECRET,
            "--region", REGION,
            "--output", "json",
        ],
        capture_output=True,
        text=True,
        check=True,
    )

    import json
    metadata = json.loads(check.stdout)

    if metadata.get("VersionIdsToStages"):
        raise RuntimeError(
            "Secret has versions but no readable current password."
        )

    print("No application password exists yet.")
    return secrets.token_hex(24), True

def initialize_database(endpoint,master_username,master_password,app_password):
    """Create or update the WordPress database user securely."""



    # MySQL option-file escaping
    def mysql_option(value):
        return '"' + value.replace("\\", "\\\\").replace('"', '\\"') + '"'

    # Password contains only hexadecimal characters
    sql = (
        "CREATE USER IF NOT EXISTS 'wordpress_app'@'%' "
        f"IDENTIFIED BY '{app_password}';\n"
        "ALTER USER 'wordpress_app'@'%' "
        f"IDENTIFIED BY '{app_password}';\n"
        "GRANT ALL PRIVILEGES ON university_results.* "
        "TO 'wordpress_app'@'%';\n"
    )

    with tempfile.TemporaryDirectory() as directory:
        option_file = os.path.join(directory, "client.cnf")

        with open(option_file, "w") as file:
            file.write("[client]\n")
            file.write(f"user={mysql_option(master_username)}\n")
            file.write(f"password={mysql_option(master_password)}\n")

        os.chmod(option_file, 0o600)

        subprocess.run(
            [
                "mysql",
                f"--defaults-extra-file={option_file}",
                "-h", endpoint,
            ],
            input=sql,
            text=True,
            check=True,
        )

    print("WordPress database user initialized successfully.")
    return app_password

def store_app_password(app_password):
    """Store a new password, safely handling an uncertain AWS response."""

    result = subprocess.run(
        [
            "aws",
            "secretsmanager",
            "put-secret-value",
            "--secret-id", APP_SECRET,
            "--secret-string", "file:///dev/stdin",
            "--region", REGION,
            "--output", "json",
        ],
        input=app_password,
        text=True,
        capture_output=True,
    )

    if result.returncode == 0:
        print("WordPress password stored successfully.")
        return

    # The write may have succeeded even if the response failed.
    # Check the current value before deciding whether to retry.
    check = subprocess.run(
        [
            "aws",
            "secretsmanager",
            "get-secret-value",
            "--secret-id", APP_SECRET,
            "--query", "SecretString",
            "--region", REGION,
            "--output", "text",
        ],
        text=True,
        capture_output=True,
    )

    if check.returncode == 0:
        if check.stdout.strip() == app_password:
            print("WordPress password storage verified.")
            return

        raise RuntimeError(
            "Secrets Manager contains a different password. "
            "Stopping to prevent credential inconsistency."
        )

    raise RuntimeError(
        "Unable to confirm whether the WordPress password was stored."
    )

    print("WordPress application password stored successfully.")

def configure_wordpress(endpoint, app_password):
    """Generate WordPress configuration securely."""

    import re

    sample_file = "/var/www/html/wp-config-sample.php"
    config_file = "/var/www/html/wp-config.php"

    if os.path.exists(config_file):
        print("wp-config.php already exists. Skipping.")
        return

    with open(sample_file, "r") as file:
        config = file.read()

    def php_string(value):
        return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"

    settings = {
        "DB_NAME": "university_results",
        "DB_USER": "wordpress_app",
        "DB_PASSWORD": app_password,
        "DB_HOST": endpoint,
    }

    for key, value in settings.items():
        pattern = rf"define\(\s*'{key}'\s*,\s*'[^']*'\s*\);"

        config, count = re.subn(
            pattern,
            lambda match, k=key, v=value: (
                f"define('{k}', {php_string(v)});"
            ),
            config,
            count=1,
        )

        if count != 1:
            raise RuntimeError(f"Could not configure {key}")

    salt_pattern = (
        r"define\(\s*'((?:AUTH|SECURE_AUTH|LOGGED_IN|NONCE)"
        r"_(?:KEY|SALT))'\s*,\s*'put your unique phrase here'\s*\);"
    )

    config, salt_count = re.subn(
        salt_pattern,
        lambda match: (
            f"define('{match.group(1)}', '{secrets.token_hex(64)}');"
        ),
        config,
    )

    if salt_count != 8:
        raise RuntimeError("Could not generate all WordPress security keys.")

    # Create the file with restricted permissions.
    old_umask = os.umask(0o077)

    try:
        with open(config_file, "x") as file:
            file.write(config)
    finally:
        os.umask(old_umask)

    apache_gid = grp.getgrnam("apache").gr_gid
    os.chown(config_file, 0, apache_gid) # root:apache on Amazon Linux
    os.chmod(config_file, 0o640)

    print("WordPress configuration created successfully.")   

def retry(operation, description, attempts=12, delay=10):
    """Retry an operation if it fails temporarily."""

    for attempt in range(1, attempts + 1):
        try:
            return operation()

        except (
            subprocess.CalledProcessError,
            ConnectionError,
            TimeoutError,
        ) as error:
            if attempt == attempts:
                raise

            print(
                f"{description} failed. "
                f"Retrying in {delay} seconds "
                f"({attempt}/{attempts})..."
            )

            time.sleep(delay) 


def get_or_create_admin_credentials():
    """Reuse stored admin credentials or generate them once."""

    admin_secret = "terraform-wordpress-admin-credentials"

    result = subprocess.run(
        [
            "aws", "secretsmanager", "get-secret-value",
            "--secret-id", admin_secret,
            "--query", "SecretString",
            "--region", REGION,
            "--output", "text",
        ],
        capture_output=True,
        text=True,
    )

    if result.returncode == 0:
        credentials = json.loads(result.stdout)

        if not credentials.get("username") or not credentials.get("password"):
            raise RuntimeError("Stored administrator credentials are incomplete.")

        print("Existing administrator credentials retrieved.")
        return credentials

    # Confirm the secret exists and has no stored versions.
    check = subprocess.run(
        [
            "aws", "secretsmanager", "describe-secret",
            "--secret-id", admin_secret,
            "--region", REGION,
            "--output", "json",
        ],
        capture_output=True,
        text=True,
        check=True,
    )

    metadata = json.loads(check.stdout)

    if metadata.get("VersionIdsToStages"):
        raise RuntimeError(
            "Administrator secret has versions but no readable current value."
        )

    alphabet = string.ascii_letters + string.digits

    credentials = {
        "username": "portal_manager",
        "password": "".join(
            secrets.choice(alphabet) for _ in range(32)
        ),
    }

    # Store before installing WordPress, so failed installations
    # can reuse the same credentials.
    subprocess.run(
        [
            "aws", "secretsmanager", "put-secret-value",
            "--secret-id", admin_secret,
            "--secret-string", "file:///dev/stdin",
            "--region", REGION,
            "--output", "none",
        ],
        input=json.dumps(credentials),
        text=True,
        check=True,
    )

    print("Administrator credentials stored successfully.")
    return credentials

def install_wordpress(site_url):
    """Install WordPress with credentials stored in Secrets Manager."""

    check = subprocess.run(
        [
            "wp", "core", "is-installed",
            "--path=/var/www/html",
            "--allow-root",
        ],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )

    if check.returncode == 0:
        print("WordPress is already installed. Skipping.")
        return

    credentials = get_or_create_admin_credentials()

    subprocess.run(
        [
            "wp", "core", "install",
            f"--url={site_url}",
            "--title=University Results Portal",
            f"--admin_user={credentials['username']}",
            "--admin_email=admin@example.com",
            "--prompt=admin_password",
            "--skip-email",
            "--path=/var/www/html",
            "--allow-root",
        ],
        input=credentials["password"] + "\n",
        text=True,
        check=True,
    )

    print("WordPress installation completed successfully.")

if __name__ == "__main__":
    print("Starting WordPress database initialization...")

    endpoint, master_secret_arn = retry(
    get_database_details,
    "RDS details retrieval"
    )
    print(f"RDS endpoint: {endpoint}")
    print("RDS master secret ARN retrieved successfully.")

    master_username, master_password = retry(
    lambda: get_master_credentials(master_secret_arn),
    "RDS master credentials retrieval"
    )

    print("RDS master credentials retrieved successfully.")
    app_password, is_new_password = retry(
    get_or_create_app_password,
    "WordPress application secret retrieval"
    )

    if is_new_password:
        store_app_password(app_password)
    retry(
    lambda: initialize_database(
        endpoint,
        master_username,
        master_password,
        app_password
    ),
    "WordPress database initialization"
)

    configure_wordpress(endpoint, app_password)

    site_url = os.environ.get("WORDPRESS_URL")

    if not site_url:
        raise RuntimeError("WORDPRESS_URL environment variable is missing.")

    install_wordpress(site_url)

    print("WordPress initialization completed successfully.")

    print("Database initialization completed.")

    print("WordPress database and configuration initialization completed.")

    print("Database initialization and secret storage completed.")