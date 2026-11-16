
# PaySuite for PrestaShop

Official PaySuite payment gateway integration for PrestaShop.

## Overview

PaySuite for PrestaShop is a payment module developed by Hypertech, LDA that integrates the PaySuite payment gateway into PrestaShop online stores.

The module enables merchants to offer PaySuite as a payment method during the checkout process.

## Features

- PaySuite payment gateway integration.
- Payment configuration through the PrestaShop administration panel.
- Secure storage of API access tokens.
- Webhook secret configuration.
- Custom pending payment order status.
- Checkout payment option integration.
- Support for MZN (Mozambican Metical).
- Server-side payment integration.

## Requirements

- PrestaShop 1.7.6.0 or later.
- PHP version compatible with your PrestaShop installation.
- Active PaySuite account.
- Valid PaySuite API access token.
- PaySuite webhook secret.
- HTTPS-enabled online store.

## Installation

1. Download the latest module package from the GitHub Releases page.
2. Log in to your PrestaShop administration panel.
3. Navigate to Modules → Module Manager.
4. Click Upload a module.
5. Select the PaySuite ZIP installation package.
6. Wait for the installation to complete.
7. Locate PaySuite in the module list.
8. Click Configure.
9. Enter your PaySuite access token.
10. Enter your PaySuite webhook secret.
11. Save the configuration.

## Configuration

After installation, configure the module using the PrestaShop administration panel.

The module requires the following credentials:

### Access Token

The API access token provided by PaySuite.

### Webhook Secret

The secret used to validate incoming PaySuite webhook signatures.

Both credentials must be configured before PaySuite becomes available as a payment option during checkout.

Existing credentials are preserved when the configuration fields are submitted empty.

## Payment Availability

The module currently supports payments in:

- MZN (Mozambican Metical).

The PaySuite payment option is only displayed when:

- The module is enabled.
- A valid access token is configured.
- A webhook secret is configured.
- The cart currency is MZN.

## Order Management

During installation, the module creates a custom order status:

`Aguardando pagamento PaySuite`

This status is intended for orders awaiting payment confirmation.

The module associates this order status with its configuration.

## Security

Security is essential when handling payment transactions.

- Never expose API access tokens.
- Never publish webhook secrets.
- Do not commit credentials to GitHub.
- Always use HTTPS.
- Validate webhook signatures on the server side.
- Verify payment information before changing order statuses.
- Do not trust payment information received directly from the browser.
- Avoid logging sensitive payment information.

## Project Structure

```text
paysuite/
├── classes/
│   └── PaySuiteApi.php
├── controllers/
│   └── ...
├── index.php
├── logo.png
├── paysuite.php
├── README.md
├── LICENSE
├── CHANGELOG.md
└── .gitignore
```

## Compatibility

| Component | Requirement |
|---|---|
| Platform | PrestaShop |
| Minimum Version | 1.7.6.0 |
| Payment Gateway | PaySuite |
| Supported Currency | MZN |
| Module Name | paysuite |

## Versioning

This project follows Semantic Versioning.

Version format:

`MAJOR.MINOR.PATCH`

Examples:

- `0.1.0` - Initial development release.
- `0.1.1` - Bug fixes.
- `0.2.0` - New features.
- `1.0.0` - First stable release.

## Releases

Official releases and installation packages will be available through GitHub Releases.

Each release should include a ZIP package ready for installation through the PrestaShop administration panel.

## Bug Reports

For bug reports and technical issues, please provide:

- PrestaShop version.
- PHP version.
- PaySuite module version.
- Description of the issue.
- Steps to reproduce the problem.
- Relevant error messages.

Never include API tokens, webhook secrets or other sensitive information in public reports.

## Support

For licensing, technical support and commercial inquiries, please contact Hypertech, LDA.

## Author

**Hypertech, LDA**

PaySuite for PrestaShop

## License

PaySuite for PrestaShop is proprietary software.

All rights reserved by Hypertech, LDA.

See the LICENSE file for licensing terms.
# prestashop-paysuite
