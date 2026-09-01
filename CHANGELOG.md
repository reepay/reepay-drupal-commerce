## Changelog
v 1.0.5
- [Fix] - Added support for Drupal 11 by widening core_version_requirement to include ^11.
- [Fix] - Fixed PHP 8.4 implicit-nullable deprecation warnings by updating capturePayment() and refundPayment() method signatures to use explicit nullable type hints (?Price).
- [Fix] - Simplified the Reepay payment gateway constructor to use parent::create() with dependency injection via container->get(), and removed the redundant payment_gateway field set that was already assigned elsewhere.