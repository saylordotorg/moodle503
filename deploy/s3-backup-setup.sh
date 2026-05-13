#!/bin/bash
# =============================================================================
# S3 Backup Bucket Setup for Moodle dev503.sylr.org
# Run this from your LOCAL machine (needs AWS CLI with admin-level access)
#
# Usage: bash s3-backup-setup.sh
# =============================================================================

set -euo pipefail

BUCKET_NAME="sylr-moodle-backups"
REGION="us-east-1"  # Change to your preferred region
ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
EC2_INSTANCE_ROLE="moodle-dev503-backup-role"
POLICY_NAME="moodle-dev503-backup-policy"
INSTANCE_PROFILE_NAME="moodle-dev503-backup-profile"

echo "=== Creating S3 Bucket ==="

# Create bucket (us-east-1 doesn't use LocationConstraint)
if [[ "$REGION" == "us-east-1" ]]; then
    aws s3api create-bucket \
        --bucket "$BUCKET_NAME" \
        --region "$REGION" 2>/dev/null || echo "Bucket may already exist, continuing..."
else
    aws s3api create-bucket \
        --bucket "$BUCKET_NAME" \
        --region "$REGION" \
        --create-bucket-configuration LocationConstraint="$REGION" 2>/dev/null || echo "Bucket may already exist, continuing..."
fi

# -----------------------------------------------------------------------------
# Bucket Configuration
# -----------------------------------------------------------------------------
echo "=== Configuring bucket ==="

# Block all public access
aws s3api put-public-access-block \
    --bucket "$BUCKET_NAME" \
    --public-access-block-configuration \
    "BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true"

# Enable versioning (protect against accidental deletes)
aws s3api put-bucket-versioning \
    --bucket "$BUCKET_NAME" \
    --versioning-configuration Status=Enabled

# Enable server-side encryption by default
aws s3api put-bucket-encryption \
    --bucket "$BUCKET_NAME" \
    --server-side-encryption-configuration '{
        "Rules": [{
            "ApplyServerSideEncryptionByDefault": {
                "SSEAlgorithm": "AES256"
            },
            "BucketKeyEnabled": true
        }]
    }'

# Lifecycle rules: transition old backups to cheaper storage, then delete
aws s3api put-bucket-lifecycle-configuration \
    --bucket "$BUCKET_NAME" \
    --lifecycle-configuration '{
        "Rules": [
            {
                "ID": "TransitionAndExpire",
                "Status": "Enabled",
                "Filter": {
                    "Prefix": "dev503/"
                },
                "Transitions": [
                    {
                        "Days": 30,
                        "StorageClass": "STANDARD_IA"
                    },
                    {
                        "Days": 90,
                        "StorageClass": "GLACIER_IR"
                    }
                ],
                "Expiration": {
                    "Days": 180
                },
                "NoncurrentVersionExpiration": {
                    "NoncurrentDays": 30
                }
            }
        ]
    }'

echo "  ✓ Public access blocked"
echo "  ✓ Versioning enabled"
echo "  ✓ AES-256 encryption enabled"
echo "  ✓ Lifecycle: Standard → IA (30d) → Glacier IR (90d) → Delete (180d)"

# -----------------------------------------------------------------------------
# IAM Policy (least-privilege for backups only)
# -----------------------------------------------------------------------------
echo "=== Creating IAM policy ==="

POLICY_DOC=$(cat <<EOF
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "AllowBackupUpload",
            "Effect": "Allow",
            "Action": [
                "s3:PutObject",
                "s3:GetObject",
                "s3:ListBucket",
                "s3:DeleteObject"
            ],
            "Resource": [
                "arn:aws:s3:::${BUCKET_NAME}",
                "arn:aws:s3:::${BUCKET_NAME}/dev503/*"
            ]
        }
    ]
}
EOF
)

POLICY_ARN=$(aws iam create-policy \
    --policy-name "$POLICY_NAME" \
    --policy-document "$POLICY_DOC" \
    --query 'Policy.Arn' --output text 2>/dev/null) || \
POLICY_ARN="arn:aws:iam::${ACCOUNT_ID}:policy/${POLICY_NAME}"

echo "  Policy ARN: $POLICY_ARN"

# -----------------------------------------------------------------------------
# IAM Role for EC2 (so the instance can write to S3 without credentials)
# -----------------------------------------------------------------------------
echo "=== Creating IAM Role for EC2 ==="

TRUST_POLICY=$(cat <<EOF
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Principal": {
                "Service": "ec2.amazonaws.com"
            },
            "Action": "sts:AssumeRole"
        }
    ]
}
EOF
)

aws iam create-role \
    --role-name "$EC2_INSTANCE_ROLE" \
    --assume-role-policy-document "$TRUST_POLICY" \
    --description "Allows Moodle dev503 EC2 to write backups to S3" \
    2>/dev/null || echo "  Role may already exist, continuing..."

aws iam attach-role-policy \
    --role-name "$EC2_INSTANCE_ROLE" \
    --policy-arn "$POLICY_ARN" \
    2>/dev/null || echo "  Policy may already be attached, continuing..."

# Create instance profile and add role
aws iam create-instance-profile \
    --instance-profile-name "$INSTANCE_PROFILE_NAME" \
    2>/dev/null || echo "  Instance profile may already exist, continuing..."

aws iam add-role-to-instance-profile \
    --instance-profile-name "$INSTANCE_PROFILE_NAME" \
    --role-name "$EC2_INSTANCE_ROLE" \
    2>/dev/null || echo "  Role may already be in profile, continuing..."

echo ""
echo "============================================="
echo "  S3 Backup Setup Complete!"
echo "============================================="
echo ""
echo "  Bucket:           s3://${BUCKET_NAME}/dev503/"
echo "  IAM Role:         ${EC2_INSTANCE_ROLE}"
echo "  Instance Profile: ${INSTANCE_PROFILE_NAME}"
echo ""
echo "  Next steps:"
echo "  1. Attach the instance profile to your EC2:"
echo "     aws ec2 associate-iam-instance-profile \\"
echo "       --instance-id <YOUR_INSTANCE_ID> \\"
echo "       --iam-instance-profile Name=${INSTANCE_PROFILE_NAME}"
echo ""
echo "  2. Test backup from EC2:"
echo "     sudo /usr/local/bin/moodle-backup.sh"
echo ""
echo "  Storage costs estimate:"
echo "    ~5GB daily backup × 30 days = ~150GB"
echo "    Standard (first 30 days):  ~\$3.45/mo"
echo "    IA (30-90 days):           ~\$1.88/mo"
echo "    Glacier IR (90-180 days):  ~\$1.50/mo"
echo "    Total steady-state:        ~\$5-7/mo"
echo "============================================="
