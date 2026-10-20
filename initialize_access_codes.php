
<?php
/**
 * Initialize synthetic student access codes securely.
 *
 * Run using WP-CLI after WordPress and the
 * University Results Portal plugin are installed.
 *
 * Never print access codes to installation logs.
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    throw new RuntimeException( 'Run this script using WP-CLI.' );
}

if (
    ! class_exists( 'URP_Access_Codes' ) ||
    ! class_exists( 'URP_Repository' )
) {
    throw new RuntimeException(
        'University Results Portal plugin is not loaded.'
    );
}

/**
 * Execute AWS CLI and capture its output securely.
 */
function urp_aws_cli( array $arguments, ?string $input = null ): string {
    $command = 'aws';

    foreach ( $arguments as $argument ) {
        $command .= ' ' . escapeshellarg( $argument );
    }

    $process = proc_open(
        $command,
        array(
            0 => array( 'pipe', 'r' ),
            1 => array( 'pipe', 'w' ),
            2 => array( 'pipe', 'w' ),
        ),
        $pipes
    );

    if ( ! is_resource( $process ) ) {
        throw new RuntimeException( 'Could not start AWS CLI.' );
    }

    try {
        if ( null !== $input ) {
            $length = strlen( $input );
            $offset = 0;

            while ( $offset < $length ) {
                $written = fwrite(
                    $pipes[0],
                    substr( $input, $offset )
                );

                if ( false === $written || 0 === $written ) {
                    throw new RuntimeException(
                        'Could not send data to AWS CLI.'
                    );
                }

                $offset += $written;
            }
        }

        fclose( $pipes[0] );

        $output = stream_get_contents( $pipes[1] );
        fclose( $pipes[1] );

        $stderr = stream_get_contents( $pipes[2] );
        fclose( $pipes[2] );

        $exit_code = proc_close( $process );

    } catch ( Throwable $exception ) {
        foreach ( $pipes as $pipe ) {
            if ( is_resource( $pipe ) ) {
                fclose( $pipe );
            }
        }

        proc_terminate( $process );
        proc_close( $process );

        throw new RuntimeException(
            'AWS CLI communication failed.'
        );
    }

    if ( 0 !== $exit_code || false === $output ) {
        throw new RuntimeException(
            'AWS CLI operation failed. Check IAM, region and secret state.'
        );
    }

    return $output;
}

$aws_region = 'us-east-1';
$secret_id  = 'terraform-wordpress-student-access-codes';

/**
 * STEP 1: Check whether the secret already has versions.
 */
$metadata_json = urp_aws_cli(
    array(
        'secretsmanager', 'describe-secret',
        '--secret-id', $secret_id,
        '--region', $aws_region,
        '--query', 'VersionIdsToStages',
        '--output', 'json',
    )
);

$versions = json_decode(
    trim( $metadata_json ),
    true,
    512,
    JSON_THROW_ON_ERROR
);

if ( null !== $versions && ! is_array( $versions ) ) {
    throw new RuntimeException(
        'Unexpected Secrets Manager version metadata.'
    );
}

$secret_is_empty = empty( $versions );
$existing_codes = array();

/**
 * STEP 2: Reuse existing codes when the secret is populated.
 */
if ( ! $secret_is_empty ) {
    $secret_json = urp_aws_cli(
        array(
            'secretsmanager', 'get-secret-value',
            '--secret-id', $secret_id,
            '--region', $aws_region,
            '--query', 'SecretString',
            '--output', 'text',
        )
    );

    $existing_codes = json_decode(
        trim( $secret_json ),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    if (
        ! is_array( $existing_codes ) ||
        array_is_list( $existing_codes )
    ) {
        throw new RuntimeException(
            'Existing secret must be a JSON object.'
        );
    }

    foreach ( $existing_codes as $reg_no => $code ) {
        if (
            ! is_string( $reg_no ) ||
            ! is_string( $code ) ||
            '' === $code
        ) {
            throw new RuntimeException(
                'Existing secret has an invalid access-code entry.'
            );
        }
    }

    WP_CLI::log(
        'Existing access-code secret loaded securely.'
    );

} else {
    WP_CLI::log(
        'Secret has no versions; first-time initialization is required.'
    );
}

/**
 * STEP 3: Find students without access-code hashes.
 */
$students = URP_Repository::students_for_codes( true );

if ( empty( $students ) ) {
    WP_CLI::log(
        'All students already have access-code hashes; nothing to change.'
    );
    return;
}

/**
 * STEP 4: Generate only missing codes.
 */
$codes_to_save = $existing_codes;
$has_new_codes = false;

foreach ( $students as $student ) {
    $reg_no = (string) $student['reg_no'];

    if ( ! array_key_exists( $reg_no, $codes_to_save ) ) {
        $codes_to_save[ $reg_no ] =
            URP_Access_Codes::generate();

        $has_new_codes = true;
    }
}

WP_CLI::log(
    sprintf(
        'Preparing codes for %d students.',
        count( $students )
    )
);

/**
 * STEP 5: Save only if new codes were generated.
 */
if ( $has_new_codes ) {
    $secret_payload = json_encode(
        $codes_to_save,
        JSON_THROW_ON_ERROR
    );

    urp_aws_cli(
        array(
            'secretsmanager', 'put-secret-value',
            '--secret-id', $secret_id,
            '--region', $aws_region,
            '--secret-string', 'file:///dev/stdin',
            '--output', 'json',
        ),
        $secret_payload
    );

    WP_CLI::log(
        'Access codes saved in AWS Secrets Manager.'
    );
}

/**
 * STEP 6: Read back the stored secret.
 */
$stored_json = urp_aws_cli(
    array(
        'secretsmanager', 'get-secret-value',
        '--secret-id', $secret_id,
        '--region', $aws_region,
        '--query', 'SecretString',
        '--output', 'text',
    )
);

$stored_codes = json_decode(
    trim( $stored_json ),
    true,
    512,
    JSON_THROW_ON_ERROR
);

if ( ! is_array( $stored_codes ) ) {
    throw new RuntimeException(
        'Stored secret is not a valid JSON object.'
    );
}

/**
 * STEP 7: Verify every required code before updating MySQL.
 */
foreach ( $students as $student ) {
    $reg_no = (string) $student['reg_no'];

    if (
        ! isset( $stored_codes[ $reg_no ] ) ||
        ! is_string( $stored_codes[ $reg_no ] ) ||
        ! hash_equals(
            $codes_to_save[ $reg_no ],
            $stored_codes[ $reg_no ]
        )
    ) {
        throw new RuntimeException(
            'Secret verification failed; database unchanged.'
        );
    }
}

WP_CLI::log(
    'Stored access codes verified successfully.'
);

/**
 * STEP 8: Save bcrypt hashes in MySQL.
 */
foreach ( $students as $student ) {
    $reg_no = (string) $student['reg_no'];

    URP_Repository::set_access_code_hash(
        (int) $student['id'],
        URP_Access_Codes::hash(
            $stored_codes[ $reg_no ]
        )
    );
}

WP_CLI::log(
    sprintf(
        'Initialized access-code hashes for %d students.',
        count( $students )
    )
);
