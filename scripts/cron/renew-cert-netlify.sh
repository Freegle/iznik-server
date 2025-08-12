#!/bin/bash

# Certificate Renewal and Netlify Custom Certificate Deployment Script
# This script renews SSL certificates using Let's Encrypt and uploads them to Netlify
# as custom certificates (handles both new provisioning and updates of existing custom certs)
#
# Required configuration in /etc/iznik.conf:
# - NETLIFY_SITE_ID: Your Netlify site ID (found at https://app.netlify.com/sites/YOUR_SITE/configuration/general)
# - NETLIFY_ACCESS_TOKEN: Your Netlify personal access token (create at https://app.netlify.com/user/applications)
# - CERT_DOMAINS: Comma-separated list of domains for certificate (e.g., "*.ilovefreegle.org,*.modtools.org")
# - CERT_EMAIL: Email address for Let's Encrypt account
# - CERT_DNS_PLUGIN: DNS plugin for certbot (e.g., "dns-google", "dns-cloudflare")
# - CERT_DNS_CREDENTIALS: Path to DNS credentials file (e.g., "/etc/google-dns-service-account")
# - CERT_STAGING: Set to "true" for Let's Encrypt staging environment (optional)
#
# Example /etc/iznik.conf entries:
# define('NETLIFY_SITE_ID', 'your-site-id-here');  // UUID from https://app.netlify.com/sites/YOUR_SITE/configuration/general
# define('NETLIFY_ACCESS_TOKEN', 'your-access-token-here');  // From https://app.netlify.com/user/applications
# define('CERT_DOMAINS', '*.ilovefreegle.org,*.modtools.org');
# define('CERT_EMAIL', 'admin@your-domain.com');
# define('CERT_DNS_PLUGIN', 'dns-google');
# define('CERT_DNS_CREDENTIALS', '/etc/google-dns-service-account');
# define('CERT_STAGING', 'false');

set -e  # Exit on any error
set -u  # Exit on undefined variables

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to log messages
log() {
    echo -e "${BLUE}[$(date '+%Y-%m-%d %H:%M:%S')]${NC} $1"
}

error() {
    echo -e "${RED}[$(date '+%Y-%m-%d %H:%M:%S')] ERROR:${NC} $1" >&2
}

success() {
    echo -e "${GREEN}[$(date '+%Y-%m-%d %H:%M:%S')] SUCCESS:${NC} $1"
}

warning() {
    echo -e "${YELLOW}[$(date '+%Y-%m-%d %H:%M:%S')] WARNING:${NC} $1"
}

# Function to show usage
usage() {
    cat << EOF
Certificate Renewal and Netlify Custom Certificate Deployment Script

Usage: $0 [OPTIONS]

This script renews SSL certificates using Let's Encrypt/Certbot and uploads them to Netlify
as custom certificates. It handles both provisioning new custom certificates and updating
existing ones.

OPTIONS:
  -h, --help           Show this help message
  -d, --dry-run        Perform a dry run without making actual changes (default)
  --execute            Actually execute certificate renewal and deployment
  --ssl-test           Only run SSL certificate testing (no renewal or deployment)
  -v, --verbose        Enable verbose output
  -f, --force          Force renewal even if certificate is not due
  -t, --test           Use Let's Encrypt staging environment
  -e, --email EMAIL    Send notification to specified email instead of CERT_EMAIL

CONFIGURATION:
This script reads configuration from /etc/iznik.conf and expects the following PHP constants:

Required:
  NETLIFY_SITE_ID      Your Netlify site ID (https://app.netlify.com/sites/YOUR_SITE/configuration/general)
  NETLIFY_ACCESS_TOKEN Your Netlify personal access token (https://app.netlify.com/user/applications)
  CERT_DOMAINS         Comma-separated domains for certificate (e.g., "*.ilovefreegle.org,*.modtools.org")
  CERT_EMAIL           Email for Let's Encrypt account
  CERT_DNS_PLUGIN      DNS plugin for certbot (e.g., "dns-google", "dns-cloudflare")
  CERT_DNS_CREDENTIALS Path to DNS credentials file

Optional:
  CERT_STAGING         Use staging environment (default: false)

Example /etc/iznik.conf entries:
  define('NETLIFY_SITE_ID', 'abc123def456');
  define('NETLIFY_ACCESS_TOKEN', 'nfp_xyz789...');
  define('CERT_DOMAINS', '*.ilovefreegle.org,*.modtools.org');
  define('CERT_EMAIL', 'admin@mysite.com');
  define('CERT_DNS_PLUGIN', 'dns-google');
  define('CERT_DNS_CREDENTIALS', '/etc/google-dns-service-account');
  define('CERT_STAGING', 'false');

DEPENDENCIES:
  - certbot (Let's Encrypt client)
  - curl (for Netlify API calls)
  - jq (for JSON processing)
  - php (to read configuration)
  - mail or sendmail (for email notifications)

NETLIFY PERMISSIONS:
Your access token needs the following scopes:
  - sites:write
  - certificates:write

EOF
}

# Parse command line arguments
DRY_RUN=true  # Default to dry run mode for safety
VERBOSE=false
FORCE_RENEWAL=false
USE_STAGING=false
NOTIFICATION_EMAIL=""  # Override email for notifications
SSL_TEST_ONLY=false  # Only run SSL testing

while [[ $# -gt 0 ]]; do
    case $1 in
        -h|--help)
            usage
            exit 0
            ;;
        -d|--dry-run)
            DRY_RUN=true
            shift
            ;;
        --execute)
            DRY_RUN=false
            shift
            ;;
        --ssl-test)
            SSL_TEST_ONLY=true
            DRY_RUN=false  # SSL testing is always "real"
            shift
            ;;
        -v|--verbose)
            VERBOSE=true
            shift
            ;;
        -f|--force)
            FORCE_RENEWAL=true
            shift
            ;;
        -t|--test)
            USE_STAGING=true
            shift
            ;;
        -e|--email)
            if [[ -n "$2" && "$2" != -* ]]; then
                NOTIFICATION_EMAIL="$2"
                shift 2
            else
                error "Option --email requires an email address"
                usage
                exit 1
            fi
            ;;
        *)
            error "Unknown option: $1"
            usage
            exit 1
            ;;
    esac
done

# Check dependencies
check_dependencies() {
    local deps=("certbot" "curl" "jq" "php")
    local missing=()
    
    for dep in "${deps[@]}"; do
        if ! command -v "$dep" >/dev/null 2>&1; then
            missing+=("$dep")
        fi
    done
    
    if [[ ${#missing[@]} -ne 0 ]]; then
        error "Missing dependencies: ${missing[*]}"
        error "Please install the missing dependencies and try again"
        exit 1
    fi
}

# Read configuration from /etc/iznik.conf
read_config() {
    local config_file="/etc/iznik.conf"
    
    if [[ ! -f "$config_file" ]]; then
        error "Configuration file not found: $config_file"
        exit 1
    fi
    
    log "Reading configuration from $config_file"
    
    # Create a temporary PHP file to safely include the config
    local temp_php_file=$(mktemp)
    cat > "$temp_php_file" << 'EOF'
<?php
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);

$config_file = $argv[1];

if (!file_exists($config_file)) {
    fprintf(STDERR, "ERROR: Configuration file not found: %s\n", $config_file);
    exit(1);
}

// Clean any output buffering before including
ob_clean();

// Include the config file
try {
    require_once($config_file);
} catch (ParseError $e) {
    fprintf(STDERR, "ERROR: Parse error in config file: %s\n", $e->getMessage());
    exit(1);
} catch (Error $e) {
    fprintf(STDERR, "ERROR: Error loading config file: %s\n", $e->getMessage());
    exit(1);
}

// Required settings
$required = ['NETLIFY_SITE_ID', 'NETLIFY_ACCESS_TOKEN', 'CERT_DOMAINS', 'CERT_EMAIL', 'CERT_DNS_PLUGIN', 'CERT_DNS_CREDENTIALS'];
foreach ($required as $const) {
    if (!defined($const)) {
        fprintf(STDERR, "ERROR: Required constant %s not defined in config\n", $const);
        exit(1);
    }
}

// Output configuration
echo 'NETLIFY_SITE_ID=' . NETLIFY_SITE_ID . "\n";
echo 'NETLIFY_ACCESS_TOKEN=' . NETLIFY_ACCESS_TOKEN . "\n";
echo 'CERT_DOMAINS=' . CERT_DOMAINS . "\n";
echo 'CERT_EMAIL=' . CERT_EMAIL . "\n";
echo 'CERT_DNS_PLUGIN=' . CERT_DNS_PLUGIN . "\n";
echo 'CERT_DNS_CREDENTIALS=' . CERT_DNS_CREDENTIALS . "\n";
echo 'CERT_STAGING=' . (defined('CERT_STAGING') ? (CERT_STAGING ? 'true' : 'false') : 'false') . "\n";
?>
EOF
    
    local config_output
    if ! config_output=$(php "$temp_php_file" "$config_file" 2>&1); then
        rm -f "$temp_php_file"
        error "Failed to read configuration: $config_output"
        error "Config file first few lines:"
        head -5 "$config_file" | sed 's/^/  /' >&2
        exit 1
    fi
    
    rm -f "$temp_php_file"
    
    # Export configuration variables
    eval "$config_output"
    
    # Override staging setting if command line option provided
    if [[ "$USE_STAGING" == "true" ]]; then
        CERT_STAGING="true"
    fi
    
    if [[ "$VERBOSE" == "true" ]]; then
        log "Configuration loaded:"
        log "  Site ID: $NETLIFY_SITE_ID"
        log "  Domains: $CERT_DOMAINS"
        log "  Email: $CERT_EMAIL"
        log "  DNS Plugin: $CERT_DNS_PLUGIN"
        log "  DNS Credentials: $CERT_DNS_CREDENTIALS"
        log "  Staging: $CERT_STAGING"
    fi
}

# Check if certificate needs renewal
check_renewal_needed() {
    if [[ "$FORCE_RENEWAL" == "true" ]]; then
        log "Forcing certificate renewal"
        return 0
    fi
    
    log "Checking if certificate renewal is needed for domains: $CERT_DOMAINS"
    
    # Try to find existing certificate by checking all domains
    local cert_found=false
    local cert_name=""
    local primary_domain
    
    # Check each domain to see if a certificate exists for any of them
    IFS=',' read -ra DOMAINS <<< "$CERT_DOMAINS"
    for domain in "${DOMAINS[@]}"; do
        domain=$(echo "$domain" | xargs)  # trim whitespace
        local clean_domain=$(echo "$domain" | sed 's/\*\.//g')  # remove wildcard
        
        # Look for certificate containing this domain
        if certbot certificates 2>/dev/null | grep -B5 -A10 "Domains:.*$domain" | grep -q "Certificate Name:"; then
            cert_name=$(certbot certificates 2>/dev/null | grep -B5 -A10 "Domains:.*$domain" | grep "Certificate Name:" | head -1 | awk '{print $3}')
            primary_domain="$clean_domain"
            cert_found=true
            log "Found existing certificate: $cert_name for domain: $domain"
            break
        fi
    done
    
    if [[ "$cert_found" == "false" ]]; then
        # No existing certificate found, renewal needed
        log "No existing certificate found for any domain"
        return 0
    fi
    
    # Check certificate expiration
    local cert_info
    cert_info=$(certbot certificates 2>/dev/null | grep -A10 "Certificate Name: $cert_name")
    
    if echo "$cert_info" | grep -q "Expiry Date:"; then
        local expiry_date
        expiry_date=$(echo "$cert_info" | grep "Expiry Date:" | awk '{print $3, $4}')
        log "Certificate expires: $expiry_date"
        
        # Check if certificate expires within 30 days
        if openssl x509 -checkend $((30*24*60*60)) -noout -in "/etc/letsencrypt/live/$cert_name/cert.pem" >/dev/null 2>&1; then
            log "Certificate is valid for more than 30 days, skipping renewal"
            return 1
        fi
    fi
    
    log "Certificate renewal needed"
    return 0
}

# Renew certificate using certbot with DNS validation
renew_certificate() {
    log "Renewing certificate for domains: $CERT_DOMAINS"
    
    # Verify DNS credentials file exists
    if [[ ! -f "$CERT_DNS_CREDENTIALS" ]]; then
        error "DNS credentials file not found: $CERT_DNS_CREDENTIALS"
        return 1
    fi
    
    # Build domain arguments
    local domain_args=()
    IFS=',' read -ra DOMAINS <<< "$CERT_DOMAINS"
    for domain in "${DOMAINS[@]}"; do
        domain=$(echo "$domain" | xargs)  # trim whitespace
        domain_args+=("-d" "$domain")
    done
    
    local certbot_args=(
        "certonly"
        "--$CERT_DNS_PLUGIN"
        "--${CERT_DNS_PLUGIN}-credentials" "$CERT_DNS_CREDENTIALS"
        "${domain_args[@]}"
        "--email" "$CERT_EMAIL"
        "--agree-tos"
        "--non-interactive"
    )
    
    if [[ "$CERT_STAGING" == "true" ]]; then
        certbot_args+=("--staging")
        warning "Using Let's Encrypt staging environment"
    fi
    
    # Always force renewal to ensure we get fresh certificates
    certbot_args+=("--force-renewal")
    
    if [[ "$DRY_RUN" == "true" ]]; then
        certbot_args+=("--dry-run")
        log "DRY RUN: Would execute: certbot ${certbot_args[*]}"
        return 0
    fi
    
    if [[ "$VERBOSE" == "true" ]]; then
        certbot_args+=("--verbose")
    fi
    
    log "Executing: certbot ${certbot_args[*]}"
    
    if ! certbot "${certbot_args[@]}"; then
        error "Certificate renewal failed"
        return 1
    fi
    
    # Verify the certificate was created with all requested domains
    log "Verifying certificate contains all requested domains..."
    local cert_created=false
    
    # Check if certificate was created by looking for any of our domains
    for domain in "${DOMAINS[@]}"; do
        domain=$(echo "$domain" | xargs)  # trim whitespace
        if certbot certificates 2>/dev/null | grep -B5 -A10 "Domains:.*$domain" | grep -q "Certificate Name:"; then
            local cert_name_check
            cert_name_check=$(certbot certificates 2>/dev/null | grep -B5 -A10 "Domains:.*$domain" | grep "Certificate Name:" | head -1 | awk '{print $3}')
            log "Certificate created: $cert_name_check"
            
            # Show all domains in the certificate
            local cert_domains
            cert_domains=$(certbot certificates 2>/dev/null | grep -A10 "Certificate Name: $cert_name_check" | grep "Domains:" | cut -d: -f2 | xargs)
            log "Certificate includes domains: $cert_domains"
            cert_created=true
            break
        fi
    done
    
    if [[ "$cert_created" == "false" ]]; then
        error "Certificate creation verification failed - no certificate found for any domain"
        return 1
    fi
    
    success "Certificate renewed successfully"
}

# Get existing custom certificate ID from Netlify
get_custom_certificate_id() {
    log "Checking for existing custom certificate on Netlify"
    
    local response
    local http_code
    
    response=$(curl -s -w "%{http_code}" \
        -H "Authorization: Bearer $NETLIFY_ACCESS_TOKEN" \
        "https://api.netlify.com/api/v1/sites/$NETLIFY_SITE_ID")
    
    http_code="${response: -3}"
    response_body="${response%???}"
    
    if [[ "$http_code" -eq 200 ]]; then
        # Check if SSL is enabled and get certificate info
        local ssl_url
        ssl_url=$(echo "$response_body" | jq -r '.ssl_url // empty')
        
        if [[ -n "$ssl_url" && "$ssl_url" != "null" && "$ssl_url" != "false" ]]; then
            log "Site has SSL enabled: $ssl_url"
        fi
        
        # Check SSL object - it might be boolean true or an object
        local ssl_info
        ssl_info=$(echo "$response_body" | jq -r '.ssl')
        
        if [[ "$ssl_info" == "true" ]]; then
            log "Site has SSL enabled (boolean true) - likely Netlify managed certificate"
        elif [[ "$ssl_info" != "null" && "$ssl_info" != "false" ]]; then
            # SSL object exists and is not boolean, try to get certificate ID
            CUSTOM_CERT_ID=$(echo "$response_body" | jq -r '.ssl.certificate_id // empty')
            if [[ -n "$CUSTOM_CERT_ID" && "$CUSTOM_CERT_ID" != "null" && "$CUSTOM_CERT_ID" != "false" ]]; then
                log "Found existing custom certificate ID: $CUSTOM_CERT_ID"
                return 0
            else
                log "SSL object found but no certificate_id: $(echo "$response_body" | jq -r '.ssl')"
            fi
        else
            log "No SSL configuration found"
        fi
    fi
    
    # If no custom certificate exists, we'll need to provision one
    log "No existing custom certificate found, will provision new one"
    CUSTOM_CERT_ID=""
    return 0
}

# Upload certificate to Netlify (handles custom certificates)
upload_to_netlify() {
    if [[ "$DRY_RUN" == "true" ]]; then
        log "DRY RUN: Would upload custom certificate to Netlify site $NETLIFY_SITE_ID"
        return 0
    fi
    
    log "Uploading custom certificate to Netlify"
    
    # Find the actual certificate directory name
    local cert_name=""
    local cert_path=""
    
    # First, show all available certificates for debugging
    if [[ "$VERBOSE" == "true" ]]; then
        log "Available certificates:"
        certbot certificates 2>/dev/null || log "No certificates found"
    fi
    
    # Try to find the most recent certificate that matches our domains
    local best_cert=""
    local best_cert_date=""
    
    IFS=',' read -ra DOMAINS <<< "$CERT_DOMAINS"
    for domain in "${DOMAINS[@]}"; do
        domain=$(echo "$domain" | xargs)  # trim whitespace
        log "Looking for certificate covering domain: $domain"
        
        # Look for certificate containing this domain
        local cert_info
        cert_info=$(certbot certificates 2>/dev/null | grep -B5 -A10 "Domains:.*$domain")
        if [[ -n "$cert_info" ]]; then
            local found_cert_name
            found_cert_name=$(echo "$cert_info" | grep "Certificate Name:" | head -1 | awk '{print $3}')
            
            if [[ -n "$found_cert_name" ]]; then
                # Get the certificate expiry to find the newest one
                local cert_date
                cert_date=$(echo "$cert_info" | grep "Expiry Date:" | head -1 | awk '{print $3, $4}')
                
                log "Found certificate '$found_cert_name' covering $domain, expiry: $cert_date"
                
                # Use this certificate if it's the first or newer than current best
                if [[ -z "$best_cert" ]] || [[ "$cert_date" > "$best_cert_date" ]]; then
                    best_cert="$found_cert_name"
                    best_cert_date="$cert_date"
                fi
            fi
        fi
    done
    
    if [[ -n "$best_cert" ]]; then
        cert_name="$best_cert"
        cert_path="/etc/letsencrypt/live/$cert_name"
        log "Using best certificate: $cert_name (expiry: $best_cert_date)"
    else
        # Fallback: use first domain without wildcard if no certificate found
        local primary_domain
        primary_domain=$(echo "$CERT_DOMAINS" | cut -d',' -f1 | sed 's/\*\.//g')
        cert_path="/etc/letsencrypt/live/$primary_domain"
        cert_name="$primary_domain"
        warning "No certificate found matching domains, using fallback: $cert_name"
    fi
    local cert_file="$cert_path/cert.pem"
    local key_file="$cert_path/privkey.pem"
    local chain_file="$cert_path/chain.pem"
    local fullchain_file="$cert_path/fullchain.pem"
    
    # Verify certificate files exist
    for file in "$cert_file" "$key_file" "$fullchain_file"; do
        if [[ ! -f "$file" ]]; then
            error "Certificate file not found: $file"
            return 1
        fi
    done
    
    # Read certificate files
    local cert_content
    local key_content
    local chain_content
    
    # For Netlify custom certificates, we need:
    # certificate: leaf certificate (cert.pem) 
    # key: private key (privkey.pem)
    # ca_certificates: intermediate chain (chain.pem)
    
    # Read the individual components
    cert_content=$(cat "$cert_file")
    key_content=$(cat "$key_file")
    
    # Read chain file for ca_certificates
    local ca_content=""
    if [[ -f "$chain_file" ]]; then
        ca_content=$(cat "$chain_file")
        log "Using separate cert.pem + chain.pem format"
        
        # Verify we have the chain
        local chain_count
        chain_count=$(grep -c "BEGIN CERTIFICATE" "$chain_file")
        log "Chain file contains $chain_count certificate(s)"
        
        if [[ "$chain_count" -eq 0 ]]; then
            error "Chain file exists but contains no certificates"
            return 1
        fi
    else
        error "No chain file found - certificate will not be trusted"
        log "Available files in $cert_path:"
        ls -la "$cert_path/" | head -10
        return 1
    fi
    
    # Validate certificate format
    if ! openssl x509 -in "$cert_file" -text -noout >/dev/null 2>&1; then
        error "Certificate file is not valid: $cert_file"
        return 1
    fi
    
    if ! openssl rsa -in "$key_file" -check -noout >/dev/null 2>&1; then
        error "Private key file is not valid: $key_file"
        return 1
    fi
    
    # Always show certificate details (not just in verbose mode) to help debug issues
    log "=== CERTIFICATE DETAILS ==="
    log "Certificate file: $cert_file"
    log "Certificate subject: $(openssl x509 -in "$cert_file" -subject -noout)"
    log "Certificate expiry: $(openssl x509 -in "$cert_file" -enddate -noout)"
    log "Certificate domains: $(openssl x509 -in "$cert_file" -text -noout | grep -A1 "Subject Alternative Name" | tail -1 | sed 's/DNS://g' || echo 'No SAN found')"
    
    # Check certificate age
    local cert_not_before
    cert_not_before=$(openssl x509 -in "$cert_file" -startdate -noout | cut -d= -f2)
    log "Certificate created: $cert_not_before"
    
    # Validate certificate chain
    log "=== CERTIFICATE CHAIN VALIDATION ==="
    if [[ -f "$fullchain_file" ]]; then
        # Verify the chain with OpenSSL
        if openssl verify -CApath /etc/ssl/certs -untrusted "$chain_file" "$cert_file" >/dev/null 2>&1; then
            log "Certificate chain verification: PASSED"
        else
            warning "Certificate chain verification: FAILED"
            log "Chain verification details:"
            openssl verify -CApath /etc/ssl/certs -untrusted "$chain_file" "$cert_file" 2>&1 | head -5
        fi
        
        # Show chain details
        local chain_info
        if [[ -f "$chain_file" ]]; then
            chain_info=$(openssl x509 -in "$chain_file" -subject -issuer -noout 2>/dev/null | head -2)
            log "Intermediate certificate details:"
            log "$chain_info"
        fi
    fi
    
    if [[ "$VERBOSE" == "true" ]]; then
        log "Certificate file: $cert_file"
        log "Key file: $key_file" 
        log "Chain file: $chain_file"
        log "Certificate content length: ${#cert_content}"
        log "Key content length: ${#key_content}"
        
        # Show certificate details
        log "Certificate subject: $(openssl x509 -in "$cert_file" -subject -noout)"
        log "Certificate expiry: $(openssl x509 -in "$cert_file" -enddate -noout)"
        log "Certificate domains: $(openssl x509 -in "$cert_file" -text -noout | grep -A1 "Subject Alternative Name" | tail -1 | sed 's/DNS://g' || echo 'No SAN found')"
        
        # Check if certificate has expired
        if ! openssl x509 -in "$cert_file" -checkend 0 >/dev/null 2>&1; then
            error "Certificate has expired! This certificate cannot be used."
            # Show certificate validity period
            log "Certificate validity: $(openssl x509 -in "$cert_file" -dates -noout)"
            return 1
        fi
        
        # Verify certificate covers the expected domains
        local cert_domains
        cert_domains=$(openssl x509 -in "$cert_file" -text -noout | grep -A1 "Subject Alternative Name" | tail -1 | sed 's/DNS://g' | tr -d ' ' || echo '')
        if [[ -n "$cert_domains" ]]; then
            log "Certificate covers domains: $cert_domains"
            
            # Check if our requested domains are covered
            local missing_domains=""
            IFS=',' read -ra REQUESTED_DOMAINS <<< "$CERT_DOMAINS"
            for req_domain in "${REQUESTED_DOMAINS[@]}"; do
                req_domain=$(echo "$req_domain" | xargs)
                if [[ "$cert_domains" != *"$req_domain"* ]]; then
                    missing_domains="$missing_domains $req_domain"
                fi
            done
            
            if [[ -n "$missing_domains" ]]; then
                warning "Certificate may not cover all requested domains. Missing:$missing_domains"
                warning "This may cause deployment issues with Netlify"
            fi
        fi
    fi
    
    # Get existing certificate ID
    get_custom_certificate_id
    
    # Validate the certificate content we're about to send
    log "=== VALIDATING CERTIFICATE FOR UPLOAD ==="
    log "Certificate file: $cert_file"
    log "Private key file: $key_file"
    log "Chain file: $chain_file"
    
    # Test certificate
    if openssl x509 -in "$cert_file" -text -noout >/dev/null 2>&1; then
        local cert_subject
        cert_subject=$(openssl x509 -in "$cert_file" -subject -noout)
        log "Certificate subject: $cert_subject"
    else
        error "Certificate file is not valid PEM format"
        return 1
    fi
    
    # Test chain
    local chain_cert_count
    chain_cert_count=$(grep -c "BEGIN CERTIFICATE" "$chain_file")
    log "Chain file contains $chain_cert_count intermediate certificate(s)"
    
    # Create JSON payload for custom certificate with separate ca_certificates
    # Use jq to properly escape the PEM content
    local json_payload
    json_payload=$(jq -n \
        --rawfile cert "$cert_file" \
        --rawfile key "$key_file" \
        --rawfile ca "$chain_file" \
        '{
            certificate: $cert,
            key: $key,
            ca_certificates: $ca
        }')
    
    log "JSON payload created with certificate, key, and ca_certificates fields"
    
    local api_url
    local http_method
    
    if [[ -n "$CUSTOM_CERT_ID" ]]; then
        # Update existing custom certificate
        api_url="https://api.netlify.com/api/v1/sites/$NETLIFY_SITE_ID/ssl"
        http_method="PUT"
        log "Updating existing custom certificate"
    else
        # Provision new custom certificate
        api_url="https://api.netlify.com/api/v1/sites/$NETLIFY_SITE_ID/ssl"
        http_method="POST"
        log "Provisioning new custom certificate"
    fi
    
    # Upload to Netlify
    local response
    local http_code
    
    if [[ "$VERBOSE" == "true" ]]; then
        log "Uploading custom certificate to Netlify API via $http_method..."
    fi
    
    response=$(curl -s -w "%{http_code}" \
        -X "$http_method" \
        -H "Authorization: Bearer $NETLIFY_ACCESS_TOKEN" \
        -H "Content-Type: application/json" \
        -d "$json_payload" \
        "$api_url")
    
    http_code="${response: -3}"
    response_body="${response%???}"
    
    if [[ "$VERBOSE" == "true" ]]; then
        log "HTTP Response Code: $http_code"
        log "Response Body: $response_body"
    fi
    
    if [[ "$http_code" -ge 200 && "$http_code" -lt 300 ]]; then
        success "Custom certificate uploaded successfully to Netlify"
        
        # Extract and store the certificate ID for future updates
        if echo "$response_body" | jq -e . >/dev/null 2>&1; then
            CUSTOM_CERT_ID=$(echo "$response_body" | jq -r '.id // empty')
            if [[ -n "$CUSTOM_CERT_ID" && "$CUSTOM_CERT_ID" != "null" && "$CUSTOM_CERT_ID" != "false" ]]; then
                log "Certificate ID: $CUSTOM_CERT_ID"
            fi
        fi
    else
        error "Failed to upload custom certificate to Netlify (HTTP $http_code)"
        error "Response: $response_body"
        
        # Parse error message if JSON
        if echo "$response_body" | jq -e . >/dev/null 2>&1; then
            local error_msg
            error_msg=$(echo "$response_body" | jq -r '.message // .error // "Unknown error"')
            error "API Error: $error_msg"
        fi
        
        # Check for common issues
        case "$http_code" in
            401)
                error "Authentication failed. Check your NETLIFY_ACCESS_TOKEN"
                ;;
            403)
                error "Access denied. Ensure your token has certificate management permissions"
                ;;
            404)
                error "Site not found. Check your NETLIFY_SITE_ID"
                ;;
            422)
                error "Certificate validation failed. This may be a domain or certificate format issue"
                ;;
        esac
        
        return 1
    fi
}

# Test SSL certificate using SSL Shopper API
test_ssl_certificate() {
    local hostname="$1"
    local max_retries=3
    local retry_count=0
    
    log "Testing SSL certificate for $hostname using SSL Shopper..."
    
    while [[ $retry_count -lt $max_retries ]]; do
        # Use SSL Shopper's SSL checker (if they have an API endpoint)
        # Alternative: use SSL Labs API or direct OpenSSL testing
        local ssl_test_result
        
        # Test with OpenSSL first (immediate)
        if ssl_test_result=$(echo | timeout 10 openssl s_client -servername "$hostname" -connect "$hostname:443" -verify_return_error 2>&1); then
            if echo "$ssl_test_result" | grep -q "Verify return code: 0 (ok)"; then
                log "✅ SSL verification PASSED for $hostname"
                
                # Extract certificate details
                local cert_subject
                local cert_expiry
                cert_subject=$(echo "$ssl_test_result" | openssl x509 -noout -subject 2>/dev/null | cut -d= -f2- || echo "Could not extract subject")
                cert_expiry=$(echo "$ssl_test_result" | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2 || echo "Could not extract expiry")
                
                log "Certificate subject: $cert_subject"
                log "Certificate expires: $cert_expiry"
                
                # Check for common SSL issues
                if echo "$ssl_test_result" | grep -q "unable to get local issuer certificate"; then
                    warning "⚠️  Certificate chain issue detected for $hostname"
                    return 1
                elif echo "$ssl_test_result" | grep -q "certificate verify failed"; then
                    warning "⚠️  Certificate verification failed for $hostname"
                    return 1
                else
                    return 0
                fi
            else
                local verify_code
                verify_code=$(echo "$ssl_test_result" | grep "Verify return code:" | cut -d: -f2- || echo "Unknown error")
                warning "❌ SSL verification FAILED for $hostname: $verify_code"
                
                ((retry_count++))
                if [[ $retry_count -lt $max_retries ]]; then
                    log "Retrying SSL test in 30 seconds... ($((retry_count+1))/$max_retries)"
                    sleep 30
                fi
            fi
        else
            warning "❌ Could not connect to $hostname for SSL testing"
            ((retry_count++))
            if [[ $retry_count -lt $max_retries ]]; then
                log "Retrying connection in 30 seconds... ($((retry_count+1))/$max_retries)"
                sleep 30
            fi
        fi
    done
    
    error "SSL testing failed for $hostname after $max_retries attempts"
    return 1
}

# Verify certificate deployment
verify_deployment() {
    if [[ "$DRY_RUN" == "true" ]]; then
        log "DRY RUN: Would verify certificate deployment"
        return 0
    fi
    
    log "=== CERTIFICATE DEPLOYMENT VERIFICATION ==="
    
    # Wait for deployment to propagate
    log "Waiting 30 seconds for certificate deployment to propagate..."
    sleep 30
    
    # Test hostnames that should be covered by the certificate
    local test_hostnames=(
        "ilovefreegle.org"
        "www.ilovefreegle.org" 
        "modtools.org"
        "www.modtools.org"
    )
    
    # Add any specific domains from configuration (if available)
    if [[ -n "${CERT_DOMAINS:-}" ]]; then
        IFS=',' read -ra CERT_DOMAINS_ARRAY <<< "$CERT_DOMAINS"
        for domain in "${CERT_DOMAINS_ARRAY[@]}"; do
            domain=$(echo "$domain" | xargs)  # trim whitespace
            if [[ "$domain" =~ ^\*\.(.*) ]]; then
                # For wildcard domains, test both apex and www
                local base_domain="${BASH_REMATCH[1]}"
                test_hostnames+=("$base_domain")
                test_hostnames+=("www.$base_domain")
            elif [[ ! "$domain" =~ ^\*\. ]]; then
                # For non-wildcard domains, add directly
                test_hostnames+=("$domain")
            fi
        done
        log "Added domains from CERT_DOMAINS configuration: $CERT_DOMAINS"
    else
        log "CERT_DOMAINS not available, using default hostnames only"
    fi
    
    # Remove duplicates and sort
    local unique_hostnames
    unique_hostnames=($(printf '%s\n' "${test_hostnames[@]}" | sort -u))
    
    log "Testing SSL certificate on ${#unique_hostnames[@]} hostnames..."
    
    local failed_hosts=()
    local passed_hosts=()
    
    for hostname in "${unique_hostnames[@]}"; do
        log "--- Testing $hostname ---"
        
        if test_ssl_certificate "$hostname"; then
            passed_hosts+=("$hostname")
        else
            failed_hosts+=("$hostname")
        fi
        
        # Brief pause between tests
        sleep 2
    done
    
    # Summary
    log "=== VERIFICATION SUMMARY ==="
    log "✅ PASSED (${#passed_hosts[@]}): ${passed_hosts[*]}"
    
    if [[ ${#failed_hosts[@]} -gt 0 ]]; then
        warning "❌ FAILED (${#failed_hosts[@]}): ${failed_hosts[*]}"
        warning "Certificate deployment verification failed on some hostnames"
        warning "You may want to check these manually at: https://www.sslshopper.com/ssl-checker.html"
        
        # Still return success if at least one host passed (partial success)
        if [[ ${#passed_hosts[@]} -gt 0 ]]; then
            log "Partial success: certificate working on some hostnames"
            return 0
        else
            log "Complete failure: certificate not working on any hostname"
            return 1
        fi
    else
        success "✅ Certificate deployment verification PASSED on all hostnames"
        return 0
    fi
}

# Send email notification
send_email_notification() {
    local subject="$1"
    local body="$2"
    local status="$3"  # SUCCESS or FAILURE
    local detailed_log="$4"  # Optional detailed log content
    
    # Check if required variables are set
    if [[ -z "$CERT_EMAIL" ]]; then
        error "CERT_EMAIL not set - cannot send email notification"
        return 1
    fi
    
    # Use fallback values if configuration variables are missing
    local cert_domains="${CERT_DOMAINS:-'Not available'}"
    local dns_plugin="${CERT_DNS_PLUGIN:-'Not available'}"
    local dns_credentials="${CERT_DNS_CREDENTIALS:-'Not available'}"
    local site_id="${NETLIFY_SITE_ID:-'Not available'}"
    
    # Determine which email to use
    local target_email="$CERT_EMAIL"
    if [[ -n "$NOTIFICATION_EMAIL" ]]; then
        target_email="$NOTIFICATION_EMAIL"
        log "Using override email: $target_email"
    fi
    
    if [[ "$DRY_RUN" == "true" ]]; then
        log "DRY RUN: Would send email notification to $target_email"
        log "Subject: $subject"
        return 0
    fi
    
    # Create email body with timestamp and details
    local email_body="Certificate Renewal Report
Generated: $(date)
Hostname: $(hostname)
Status: $status
Domains: $cert_domains
DNS Plugin: $dns_plugin
DNS Credentials: $dns_credentials
Netlify Site ID: $site_id

SUMMARY:
$body"

    # Add detailed log if provided
    if [[ -n "$detailed_log" ]]; then
        email_body="$email_body

DETAILED LOG:
$detailed_log"
    fi

    email_body="$email_body

---
This email was generated automatically by the certificate renewal script.
Script location: $0
"
    
    # Try to send email using available methods
    if command -v mail >/dev/null 2>&1; then
        echo "$email_body" | mail -s "$subject" "$target_email"
        log "Email notification sent via mail command to $target_email"
    elif command -v sendmail >/dev/null 2>&1; then
        {
            echo "To: $target_email"
            echo "Subject: $subject"
            echo "Content-Type: text/plain"
            echo ""
            echo "$email_body"
        } | sendmail "$target_email"
        log "Email notification sent via sendmail to $target_email"
    else
        warning "No email command available (mail or sendmail). Email notification skipped."
        # Log the notification details for manual review
        log "Email notification would have been sent:"
        log "To: $target_email"
        log "Subject: $subject"
        log "Body: $email_body"
    fi
}

# Main execution
main() {
    # Create a temporary file to capture detailed logs
    local log_file=$(mktemp)
    
    # Function to capture logs
    capture_log() {
        local message="$1"
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] $message" | tee -a "$log_file" >&2
    }
    
    if [[ "$SSL_TEST_ONLY" == "true" ]]; then
        capture_log "SSL TESTING MODE - Only running certificate verification"
        capture_log "Starting SSL certificate testing"
        
        # For SSL testing, we still need to load config for CERT_DOMAINS
        check_dependencies
        read_config
        echo "Configuration loaded for SSL testing" | tee -a "$log_file"
        
        # Run SSL testing and exit
        if verify_deployment; then
            success "SSL certificate testing completed successfully"
            local log_content=$(cat "$log_file")
            send_email_notification "SSL Certificate Testing PASSED" \
                "SSL certificate testing completed successfully. All tested hostnames have valid, trusted certificates." \
                "SUCCESS" "$log_content"
            rm -f "$log_file"
            exit 0
        else
            error "SSL certificate testing failed"
            local log_content=$(cat "$log_file")
            send_email_notification "SSL Certificate Testing FAILED" \
                "SSL certificate testing failed. Some hostnames have certificate issues that need attention." \
                "FAILURE" "$log_content"
            rm -f "$log_file"
            exit 1
        fi
    fi
    
    if [[ "$DRY_RUN" == "true" ]]; then
        capture_log "RUNNING IN DRY-RUN MODE - No actual changes will be made"
        capture_log "To actually execute certificate renewal, use: $0 --execute"
        echo
    fi
    
    capture_log "Starting certificate renewal process"
    
    # Check dependencies and capture output
    check_dependencies 2>&1 | tee -a "$log_file"
    
    # Read config in main shell (not subshell) to preserve variables
    read_config
    echo "Configuration loaded successfully" | tee -a "$log_file"
    
    # Since we always force renewal, skip the renewal check
    # if ! check_renewal_needed; then
    #     if [[ "$DRY_RUN" == "true" ]]; then
    #         success "DRY RUN: Certificate renewal not needed"
    #         log "To force renewal in dry-run mode: $0 --dry-run --force"
    #     else
    #         success "Certificate renewal not needed"
    #     fi
    #     exit 0
    # fi
    
    local renewal_success=false
    local upload_success=false
    local verification_success=false
    
    # Execute renewal and capture output without affecting variable scope
    {
        if ! renew_certificate; then
            echo "RENEWAL_FAILED" >> "$log_file"
            exit 1
        fi
    } 2>&1 | tee -a "$log_file"
    
    if grep -q "RENEWAL_FAILED" "$log_file"; then
        error "Certificate renewal failed"
        local log_content=$(cat "$log_file")
        send_email_notification "Certificate Renewal FAILED for $CERT_DOMAINS" \
            "Certificate renewal failed during the certbot execution phase. Please check the detailed log below for error information." \
            "FAILURE" "$log_content"
        rm -f "$log_file"
        exit 1
    fi
    renewal_success=true
    
    # Execute upload and capture output
    {
        if ! upload_to_netlify; then
            echo "UPLOAD_FAILED" >> "$log_file"
            exit 1
        fi
    } 2>&1 | tee -a "$log_file"
    
    if grep -q "UPLOAD_FAILED" "$log_file"; then
        error "Failed to upload certificate to Netlify"
        local log_content=$(cat "$log_file")
        send_email_notification "Certificate Renewal PARTIAL SUCCESS for $CERT_DOMAINS" \
            "Certificate was renewed successfully but failed to upload to Netlify. The certificate files are available in /etc/letsencrypt/live/ but manual upload to Netlify is required." \
            "FAILURE" "$log_content"
        rm -f "$log_file"
        exit 1
    fi
    upload_success=true
    
    # Execute verification and capture output
    {
        if ! verify_deployment; then
            echo "VERIFICATION_FAILED" >> "$log_file"
        fi
    } 2>&1 | tee -a "$log_file"
    
    if grep -q "VERIFICATION_FAILED" "$log_file"; then
        warning "Certificate verification failed, but upload was successful"
        verification_success=false
    else
        verification_success=true
    fi
    
    local log_content=$(cat "$log_file")
    
    # Debug: Check if configuration variables are set
    if [[ -z "$CERT_DOMAINS" ]]; then
        error "CERT_DOMAINS is not set - configuration loading failed"
        echo "CERT_DOMAINS: '$CERT_DOMAINS'"
        echo "CERT_EMAIL: '$CERT_EMAIL'"
        echo "NETLIFY_SITE_ID: '$NETLIFY_SITE_ID'"
        rm -f "$log_file"
        exit 1
    fi
    
    if [[ "$DRY_RUN" == "true" ]]; then
        success "DRY RUN: Certificate renewal and deployment would have completed successfully"
        log "To actually execute: $0 --execute"
        send_email_notification "Certificate Renewal DRY RUN Completed for $CERT_DOMAINS" \
            "Dry run completed successfully. All steps would have executed correctly. Use --execute flag to perform actual renewal." \
            "SUCCESS" "$log_content"
    else
        success "Certificate renewal and deployment completed successfully"
        local verification_msg=""
        if [[ "$verification_success" == "true" ]]; then
            verification_msg="Certificate verification passed successfully."
        else
            verification_msg="Certificate verification had warnings but deployment was successful."
        fi
        
        send_email_notification "Certificate Renewal SUCCESS for $CERT_DOMAINS" \
            "Certificate renewal, upload to Netlify, and deployment completed successfully. $verification_msg All domains should now have fresh SSL certificates." \
            "SUCCESS" "$log_content"
    fi
    
    # Clean up log file
    rm -f "$log_file"
}

# Run main function
main "$@"