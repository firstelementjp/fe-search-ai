# Security Policy

## Supported Versions

| Version | Supported |
| ------- | --------- |
| 1.0.0+  | ✅ Yes    |
| < 1.0.0 | ❌ No     |

## Reporting a Vulnerability

### 🚨 Please Do NOT Report Security Issues Publicly

To protect our users, **do not** open a public issue for security vulnerabilities.

### How to Report

**Primary Method**: Email us directly

- Send to: security@firstelement.co.jp
- Use subject: `[FE Search AI Security] Brief description of issue`

**Alternative Method**: GitHub Security Advisories (if enabled)

1. Go to our [Security Advisories](https://github.com/firstelementjp/fe-search-ai/security/advisories)
2. Click "Report a vulnerability"
3. Fill in the details with as much information as possible

### What to Include

Please include:

- **Type of vulnerability** (XSS, SQL injection, CSRF, API key exposure, etc.)
- **Affected versions** of the plugin
- **Steps to reproduce** the issue
- **Proof of concept** if available
- **Potential impact** assessment
- **Suggested mitigation** (if you have one)

### Response Timeline

- **Initial response**: Within 48 hours
- **Assessment**: Within 5 business days
- **Resolution**: As soon as possible, based on severity
- **Public disclosure**: After fix is released and users have had time to update

## Security Best Practices

### For Users

- **Keep updated**: Always use the latest version
- **Secure API keys**: Store API keys securely in plugin settings
- **Review permissions**: Only grant search settings to trusted users
- **Monitor usage**: Check search logs for suspicious activity
- **Backup data**: Maintain regular site backups

### For Developers

- **Validate input**: All user input should be properly sanitized
- **Use nonce**: Protect AJAX requests with WordPress nonces
- **Check capabilities**: Verify user permissions before operations
- **Escape output**: Use WordPress escaping functions
- **Encrypt sensitive data**: API keys must be encrypted before storage

### For Site Administrators

- **Regular updates**: Enable automatic plugin updates
- **User roles**: Limit search settings to necessary users
- **Monitor logs**: Check sync and search logs for suspicious activity
- **API key rotation**: Regularly rotate AI API keys
- **Backup strategy**: Maintain regular site backups

## Security Features in FE Search AI

FE Search AI includes several security measures:

- **WordPress nonce protection** for all AJAX requests
- **Capability checking** for search and sync operations
- **Input sanitization** for all user input
- **API key encryption** using OpenSSL
- **Rate limiting** for API calls
- **Batch processing** to avoid timeout issues
- **Error handling** with graceful degradation

## Known Limitations

- **API key security**: API keys are encrypted but stored in the database
- **External API dependencies**: Security depends on AI provider security
- **Large content sets**: Very large sync operations may require increased memory limits
- **User permissions**: Plugin respects WordPress user roles, which should be properly configured

## Security Updates

Security updates are:

- **Priority**: Released as soon as possible
- **Backported**: To supported minor versions when necessary
- **Documented**: In release notes with security implications

## Acknowledgments

We thank security researchers who help us keep FE Search AI secure. All valid security reports will be acknowledged in our release notes (with reporter's permission).

## Legal

This security policy is provided as-is without warranty. We reserve the right to modify this policy at any time.

---

**Remember**: If you discover a security vulnerability, please report it privately first. This helps us protect all our users while we work on a fix.
