# Changelog

## 1.2.4 - 2026-09-02

- Only collect Retail Delivery Fee when the Stripe account is registered to collect it.
- Stripe API upgraded to 2026-06-24.dahlia.

## 1.2.3 - 2026-06-16

- Handle Stripe errors and Magento exceptions separately when calculating the tax.
- Added explicit ACL check for tax classes controller.

## 1.2.2 - 2026-06-04

- Add ACL to the Stripe tax classes menu item and controller. (MAGENTO-1111)
- Upgrade Stripe API to latest version. (MAGENTO-1121)

## 1.2.1 - 2026-06-03

- Plugin updated to use the explicit class instead of the interface for CartTotalRepository.

## 1.2.0 - 2026-05-18

- Upgraded Stripe PHP SDK to version 20.1.
- PHP 8.5 compatibility.
- In multi-Stripe account configurations, issuing credit memos on secondary store views would not create a tax reversal. (MAGENTO-1100)
- Fixed tax transaction not recording for redirect flow (Stripe Checkout).

## 1.1.5 - 2026-05-06

- Integrate flat fees into the order totals. (MAGENTO-1091)

## 1.1.4 - 2026-04-17

- Adjustments for compliance with Adobe Commerce coding standards.

## 1.1.3 - 2026-01-20

- Exceptions during Tax reversal process will not stop the creation of the credit memo.
- The reference for transaction line items will contain the quote item id.
- Credit memos consisting only of adjustments will be able to proceed without triggering the Tax reversal.

## 1.1.2 - 2025-04-28

- Fix non integer value for quantity being sent to the Stripe API for tax reversals.

## 1.1.1 - 2025-04-11

- Compatibility with Magento 2.4.8 and PHP 8.4.

## 1.1.0 - 2025-03-12

- Added tax exemptions based on customer groups.
- Added CLI command which reverts the tax for a specific order item.
- Added customer address verification at the checkout level.

## 1.0.3 - 2025-01-10

- Fixed issue where the tax breakdown coming back from Stripe contains two identical records.
- Added MFTF tests.

## 1.0.2 - 2024-11-20

- Fixed issue when the currencies being used have different precisions for the Stripe API.
- Use the inverse of the base to current currency instead of the rate saved in the DB, in case the rates are not the exact inverse of on another.

## 1.0.1 - 2024-10-22

- Added the possibility for a 3rd party developer to add a custom fee in the tax calculation process.
- Added instructions on how the 3rd party developer can integrate their custom fee in `resources/cookbooks/Integrate_Custom_Fee.md`.

## 1.0.0 - 2024-09-10

Initial release. Supports Adobe Commerce and Magento Open Source 2.3.7 - 2.4.7. Supported PHP versions are 7.4 - 8.3.