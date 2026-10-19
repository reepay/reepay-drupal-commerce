## Changelog
[Unrelease]
- [Feature] - Click to Pay: the General payment method (Reepay) now appends "click_to_pay" (once) to the session's payment_methods when "visa", "mc" or "card" is selected. No new setting; the merchant is enabled through Frisbii admin.
- [Feature] - Added Klarna as a new payment method: selectable in the General payment method's (Reepay) payment_method list, and as a new dedicated single-payment-method gateway (Klarna, id=reepay_klarna) that always sends payment_methods=["klarna"] to the Frisbii API, including for the checkout session's own webhook registration.
- [Improvement] - Extracted the credentials, checkout-session return/notify handling (incl. webhook signature verification), and capture/refund/void logic shared by all Frisbii gateways out of Reepay into a new abstract ReepayGatewayBase, so additional single-payment-method gateways don't duplicate that logic.
- [Improvement] - ReepayOffsiteRedirectForm now resolves the payment_methods value to send via the gateway plugin's getReepayPaymentMethods(), instead of reading the General payment method's configuration directly, so the same form works for both gateway types.
- [Improvement] - Added payload logging to ReepayService::createChargeSession() so the outgoing payment_methods value (and rest of the session payload) can be verified via watchdog logs when a payment can't be tested end-to-end.
- [Fix] - Fixed "Undefined array key" warnings for locale (ReepayGatewayBase) and payment_method (Reepay) shown when configuring a newly-added gateway, by giving both a proper default in defaultConfiguration().
- [Security] - Added HMAC-SHA256 signature verification to Reepay::onNotify(), with the webhook secret cached for 10 minutes and a single refetch-and-retry on mismatch to tolerate secret rotation.
- [Security] - onNotify() now validates the payload and event_type, only authorizing/completing payments for invoice_authorized/invoice_settled; unsigned, mis-signed, or unrecognized events are rejected before touching any entity.
- [Improvement] - Added ReepayService::logWebhookEvent() to trace the webhook flow end-to-end (received, verified/rejected, skipped, order not found/placed, payment updated) without logging secrets or signatures.
- [Fix] - getNotifyUrl() hardcoded the gateway route parameter to "reepay_checkout" instead of the real config entity ID, causing 404s (and silently dropped webhooks) on any site using a different machine name. Removed the override so it inherits the correct parent implementation.
- [Fix] - Fixed a fatal error ("Call to a member function id() on null") when saving the gateway config form, caused by $this->parentEntity being unpopulated during submitConfigurationForm(). Added buildNotifyUrlFromFormState() to resolve the gateway ID from form state instead.

v 1.0.5
- [Fix] - Added support for Drupal 11 by widening core_version_requirement to include ^11.
- [Fix] - Fixed PHP 8.4 implicit-nullable deprecation warnings by updating capturePayment() and refundPayment() method signatures to use explicit nullable type hints (?Price).
- [Fix] - Simplified the Reepay payment gateway constructor to use parent::create() with dependency injection via container->get(), and removed the redundant payment_gateway field set that was already assigned elsewhere.