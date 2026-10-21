#!/usr/bin/env python3
"""Builds docs/architecture.svg, the AWS architecture diagram used in the README.

Diagram as code: edit the layout below, then run

    python3 docs/diagram/build_architecture.py

Standard library only. Icons are official AWS Architecture Icons, copied into
docs/diagram/icons/ (see README.md there). Solid elements are deployed today;
planned resources have faded icons, orange
labels and dashed outlines.
"""

import html
import re
from pathlib import Path

HERE = Path(__file__).resolve().parent
ICONS = HERE / "icons"
OUT = HERE.parent / "architecture.svg"

W, H = 1560, 1080

# AWS architecture-diagram group colours.
INK = "#232F3E"        # AWS Cloud, text
REGION = "#00A4A6"     # Region (dashed)
VPC = "#8C4FFF"        # VPC
PUBLIC = "#7AA116"     # Public subnet
PRIVATE = "#00A4A6"    # Private subnet
AZ = "#147EBA"         # Availability Zone (dashed)
PLANNED = "#ED7100"    # Planned items (dashed orange)
MUTED = "#545B64"

# Three layers so connectors never cover labels: boxes, then lines, then icons and text.
back, lines, front = [], [], []


def esc(text):
    return html.escape(text, quote=True)


def icon(name, x, y, size, faded=False):
    """Inline an icon file as a nested <svg> so the output is self-contained."""
    src = (ICONS / name).read_text()
    view_box = re.search(r'viewBox="([^"]+)"', src).group(1)
    inner = re.sub(r"^.*?<svg[^>]*>|</svg>\s*$", "", src, flags=re.S)
    inner = re.sub(r"<title>.*?</title>", "", inner, flags=re.S)
    opacity = ' opacity="0.45"' if faded else ""
    front.append(f'<svg x="{x}" y="{y}" width="{size}" height="{size}" viewBox="{view_box}"{opacity}>{inner}</svg>')


def text(x, y, value, size=13, weight="normal", color=INK, anchor="middle", halo=True, halo_color="#FFFFFF"):
    """Text with a white outline (halo) so it stays readable over lines and borders."""
    outline = f' stroke="{halo_color}" stroke-width="4" stroke-linejoin="round" paint-order="stroke"' if halo else ""
    for i, line in enumerate(value.split("\n")):
        front.append(f'<text x="{x}" y="{y + i * (size + 4)}" font-size="{size}" font-weight="{weight}" '
                     f'fill="{color}" text-anchor="{anchor}"{outline}>{esc(line)}</text>')


def node(name, cx, y, label, size=56, planned=False, sub=None, faded=None):
    """Icon centred on cx with a bold label and an optional detail line underneath.

    Planned resources: faded icon, orange label, and "planned" in the detail line.
    """
    icon(name, cx - size / 2, y, size, planned if faded is None else faded)
    text(cx, y + size + 20, label, 13, "bold", PLANNED if planned else INK)
    if sub:
        lines_in_label = label.count("\n") + 1
        text(cx, y + size + 20 + lines_in_label * 17, sub, 12, "normal", PLANNED if planned else MUTED)


def group(x, y, w, h, color, label, icon_name=None, dashed=False, fill="none"):
    dash = ' stroke-dasharray="8 5"' if dashed else ""
    back.append(f'<rect x="{x}" y="{y}" width="{w}" height="{h}" fill="{fill}" stroke="{color}" stroke-width="2"{dash}/>')
    tx = x + 10
    if icon_name:
        icon(icon_name, x, y, 30)
        tx = x + 38
    text(tx, y + 20, label, 14, "bold", color, "start", halo_color="#FFFFFF" if fill == "none" else fill)


def arrow(points, label=None, at=None, planned=False, anchor="middle"):
    stroke = PLANNED if planned else INK
    dash = ' stroke-dasharray="7 5"' if planned else ""
    marker = "arrow-planned" if planned else "arrow"
    d = "M " + " L ".join(f"{px} {py}" for px, py in points)
    lines.append(f'<path d="{d}" fill="none" stroke="{stroke}" stroke-width="2"{dash} marker-end="url(#{marker})"/>')
    if label:
        text(at[0], at[1], label, 12, "bold" if planned else "normal", PLANNED if planned else MUTED, anchor)


# ----------------------------------------------------------------- Title and legend

text(30, 42, "University Results Portal — AWS architecture", 24, "bold", INK, "start", halo=False)
text(30, 66, "Terraform-managed WordPress portal in us-east-1. Planned resources have faded icons, orange labels and dashed outlines.",
     14, "normal", MUTED, "start", halo=False)
back.append('<rect x="1190" y="24" width="340" height="50" rx="6" fill="#F7F8F8" stroke="#D5DBDB"/>')
lines.append(f'<line x1="1206" y1="49" x2="1256" y2="49" stroke="{INK}" stroke-width="2" marker-end="url(#arrow)"/>')
text(1264, 54, "Deployed", 13, "normal", INK, "start", halo=False)
lines.append(f'<line x1="1350" y1="49" x2="1400" y2="49" stroke="{PLANNED}" stroke-width="2" '
             f'stroke-dasharray="7 5" marker-end="url(#arrow-planned)"/>')
text(1408, 54, "Planned", 13, "bold", PLANNED, "start", halo=False)

# ----------------------------------------------------------------- Groups

group(215, 96, 1325, 920, INK, "AWS Cloud", "AWSCloudlogo.svg")
group(235, 140, 1285, 860, REGION, "Region: us-east-1", "Region.svg", dashed=True)
group(300, 184, 840, 796, VPC, "VPC 10.10.0.0/16", "VirtualprivatecloudVPC.svg")

AZA_X, AZB_X, AZ_W = 340, 750, 370
CA = AZA_X + AZ_W / 2          # centre of AZ A column
CB = AZB_X + AZ_W / 2          # centre of AZ B column
MID = (AZA_X + AZ_W + AZB_X) / 2  # between the two AZs

for x, label in ((AZA_X, "Availability Zone A"), (AZB_X, "Availability Zone B")):
    group(x, 226, AZ_W, 738, AZ, label, dashed=True)

TIERS = [  # y, height, colour, fill, icon, label in AZ A, label in AZ B
    (262, 196, PUBLIC, "#F2F6E8", "Publicsubnet.svg", "Public subnet 10.10.1.0/24", "Public subnet 10.10.2.0/24"),
    (478, 244, PRIVATE, "#E6F6F7", "Privatesubnet.svg", "Private app subnet 10.10.11.0/24", "Private app subnet 10.10.12.0/24"),
    (742, 208, PRIVATE, "#E6F6F7", "Privatesubnet.svg", "Private DB subnet 10.10.21.0/24", "Private DB subnet 10.10.22.0/24"),
]
for y, h, color, fill, ic, label_a, label_b in TIERS:
    group(AZA_X + 12, y, AZ_W - 24, h, color, label_a, ic, fill=fill)
    group(AZB_X + 12, y, AZ_W - 24, h, color, label_b, ic, fill=fill)

# Planned area for the Auto Scaling group (dashed orange, light orange fill)
back.append(f'<rect x="{AZB_X + 32}" y="514" width="{AZ_W - 64}" height="192" rx="6" '
            f'fill="#FDF1E6" stroke="{PLANNED}" stroke-width="2" stroke-dasharray="8 5"/>')

# Regional services panel (inside the Region, outside the VPC)
back.append('<rect x="1165" y="184" width="330" height="796" fill="#FAFAFA" stroke="#D5DBDB"/>')
text(1330, 210, "Regional services", 14, "bold", INK, halo=False)
text(1330, 228, "reached from EC2 through the NAT Gateway", 12, "normal", MUTED, halo=False)

# ----------------------------------------------------------------- Nodes

# Outside AWS
node("Users.svg", 105, 270, "Students &\nadministrators", 60, sub="web browser")
node("GitRepository.svg", 105, 560, "GitHub repository", 52, sub="plugin + scripts\nat a pinned commit")
back.append('<rect x="25" y="816" width="160" height="96" rx="6" fill="#F7F8F8" stroke="#D5DBDB"/>')
text(105, 842, "Terraform CLI", 14, "bold", halo=False)
text(105, 862, "developer machine\nlocal state\n42 resources", 12, "normal", MUTED, halo=False)

# Edge of the VPC
node("AmazonVPCInternetGateway.svg", 300, 290, "Internet Gateway", 44)

# Public tier
node("ElasticLoadBalancingApplicationLoadBalancer.svg", MID, 282, "Application Load Balancer", 60,
     sub="HTTP :80 listener, nodes in both AZs")
node("AmazonVPCNATGateway.svg", AZA_X + 95, 364, "NAT Gateway", 44, sub="outbound only")

# App tier
node("AmazonEC2.svg", CA, 546, "EC2 · WordPress", 56, sub="Amazon Linux 2023, t3.micro\nApache, PHP, results plugin")
node("AmazonEC2AutoScaling.svg", CB, 546, "Auto Scaling group", 56, planned=True, faded=False,
     sub="planned\nLaunch Template across AZ A and B")

# DB tier
node("AmazonRDS.svg", CA, 800, "RDS MySQL (primary)", 56, sub="db.t3.micro, encrypted\nstudents, results, WordPress")
node("AmazonRDS.svg", CB, 800, "RDS read replica", 56, planned=True, sub="planned")

# Regional services
node("AWSSecretsManager.svg", 1330, 254, "Secrets Manager", 56, sub="DB, admin and student\naccess-code secrets")
node("AWSIdentityAccessManagementRole.svg", 1330, 416, "IAM role", 50,
     sub="instance profile, least-privilege\nSecrets Manager + RDS access")
node("AWSSystemsManagerSessionManager.svg", 1330, 572, "Systems Manager", 50,
     sub="Session Manager admin shell\n(no SSH, no public IP)")
node("AmazonCloudWatch.svg", 1330, 760, "Amazon CloudWatch", 56, planned=True,
     sub="planned\nlogs, metrics and alarms")

# ----------------------------------------------------------------- Connections

IGW_Y = 312  # centre line of the Internet Gateway and the ALB
arrow([(145, 300), (276, IGW_Y)], "HTTP :80", (205, 290))                       # users -> IGW
arrow([(324, IGW_Y), (MID - 32, IGW_Y)])                                         # IGW -> ALB
arrow([(MID - 14, 344), (MID - 14, 468), (CA + 12, 468), (CA + 12, 544)], ":80", (CA + 22, 528), anchor="start")
arrow([(MID + 14, 344), (MID + 14, 468), (CB - 12, 468), (CB - 12, 544)], ":80", (CB - 22, 528),
      planned=True, anchor="end")                                                # ALB -> ASG (planned)
arrow([(CA - 28, 574), (AZA_X + 95, 574), (AZA_X + 95, 452)], "outbound", (AZA_X + 103, 560), anchor="start")
arrow([(AZA_X + 72, 386), (312, 386), (312, 336)])                               # NAT -> IGW
arrow([(CA, 654), (CA, 798)], "MySQL :3306", (CA + 10, 790), anchor="start")     # EC2 -> RDS
arrow([(278, 326), (258, 326), (258, 586), (136, 586)], "downloads at boot", (196, 578))  # via NAT/IGW
arrow([(185, 864), (213, 864)])                                                  # Terraform -> AWS
text(199, 852, "apply", 12, "normal", MUTED)

text(30, 1050, "Security groups: Internet → ALB :80 · ALB → EC2 :80 · EC2 → RDS :3306. Private DB subnets have no "
               "internet route. HTTPS (ACM), shared uploads and a second NAT Gateway are separate future improvements.",
     12, "normal", MUTED, "start", halo=False)

# ----------------------------------------------------------------- Write

svg = [
    f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="{W}" height="{H}" '
    f'font-family="Helvetica, Arial, sans-serif" role="img" aria-label="AWS architecture of the University Results Portal">',
    f'''<defs>
  <marker id="arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
    <path d="M0 0 L10 5 L0 10 z" fill="{INK}"/></marker>
  <marker id="arrow-planned" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
    <path d="M0 0 L10 5 L0 10 z" fill="{PLANNED}"/></marker>
</defs>''',
    f'<rect width="{W}" height="{H}" fill="#FFFFFF"/>',
    *back, *lines, *front,
    "</svg>",
]
OUT.write_text("\n".join(svg) + "\n")
print(f"Wrote {OUT.relative_to(HERE.parent.parent)} ({OUT.stat().st_size // 1024} KB)")
