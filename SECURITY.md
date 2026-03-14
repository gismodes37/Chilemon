# Security Policy

## Supported Versions

ChileMon is currently in early development. Security updates will be provided for the most recent release.

| Version | Supported |
|--------|-----------|
| 0.1.x  | Yes |
| < 0.1  | No |

---

## Reporting a Vulnerability

If you discover a security vulnerability in ChileMon, please report it responsibly.

Do **not open a public issue** describing the vulnerability.

Instead contact the maintainer directly:

**Email:** ca2iig@qsl.net

Please include:

- description of the vulnerability
- steps to reproduce
- potential impact
- possible mitigation (if known)

We will review the report and respond as soon as possible.

---

## Security Principles

ChileMon follows several design principles to maintain node safety:

- ChileMon **does not modify Asterisk configuration**
- ChileMon **does not execute arbitrary shell commands**
- All Asterisk operations are performed through a **controlled wrapper**
- Access to system commands is restricted through **sudo rules**
- Node data is stored locally using **SQLite**

Example wrapper used:

```php
/usr/local/bin/chilemon-rpt
```

This wrapper allows only the following operations:

 - rpt nodes
 - rpt stats
 - rpt connect
 - rpt disconnect

 ---

## Responsible Disclosure

Security issues will be addressed as quickly as possible.

If a vulnerability affects existing releases, a patch release will be published.

Example:

 - v0.1.1
 - v0.2.1

 
---

Thank you for helping improve the security of ChileMon.

