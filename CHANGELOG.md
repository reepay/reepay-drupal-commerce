## Changelog
[Unrelease]
- [Security] - Added HMAC-SHA256 signature verification to Reepay::onNotify(), with the webhook secret cached for 10 minutes and a single refetch-and-retry on mismatch to tolerate secret rotation.
- [Security] - onNotify() now validates the payload and event_type, only authorizing/completing payments for invoice_authorized/invoice_settled; unsigned, mis-signed, or unrecognized events are rejected before touching any entity.
- [Improvement] - Added ReepayService::logWebhookEvent() to trace the webhook flow end-to-end (received, verified/rejected, skipped, order not found/placed, payment updated) without logging secrets or signatures.
- [Fix] - getNotifyUrl() hardcoded the gateway route parameter to "reepay_checkout" instead of the real config entity ID, causing 404s (and silently dropped webhooks) on any site using a different machine name. Removed the override so it inherits the correct parent implementation.
- [Fix] - Fixed a fatal error ("Call to a member function id() on null") when saving the gateway config form, caused by $this->parentEntity being unpopulated during submitConfigurationForm(). Added buildNotifyUrlFromFormState() to resolve the gateway ID from form state instead.

v 1.0.5
- [Fix] - Added support for Drupal 11 by widening core_version_requirement to include ^11.
- [Fix] - Fixed PHP 8.4 implicit-nullable deprecation warnings by updating capturePayment() and refundPayment() method signatures to use explicit nullable type hints (?Price).
- [Fix] - Simplified the Reepay payment gateway constructor to use parent::create() with dependency injection via container->get(), and removed the redundant payment_gateway field set that was already assigned elsewhere.