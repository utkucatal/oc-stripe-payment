# Stripe Payment Module for OpenCart

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![OpenCart](https://img.shields.io/badge/OpenCart-3.x-blue.svg)](https://www.opencart.com)

A Stripe payment integration for OpenCart 3.x that uses the [Payment Element](https://stripe.com/docs/payments/payment-element) — Stripe's embedded UI that automatically presents the most relevant payment methods based on the customer's location and currency (cards, Apple Pay, Google Pay, iDEAL, Bancontact, SEPA Direct Debit, and more).

## Features

- Single integration that supports 30+ payment methods out of the box
- Server-side PaymentIntent creation with idempotency key protection
- Webhook-driven order confirmation for delayed payment methods (SEPA, Sofort)
- Race condition protection between the checkout confirm endpoint and webhooks
- Amount and currency double-checked server-side before fulfilling any order
- CSRF token protection on the confirm endpoint
- Test and live key support

## Requirements

- OpenCart 3.x
- PHP 7.4 or higher
- A [Stripe account](https://dashboard.stripe.com/register)

## Installation

1. Download the latest `stripe_payment_vX.X.X.ocmod.zip` from the [Releases](../../releases) page
2. Go to **Extensions → Installer**, upload the zip file
3. Go to **Extensions → Modifications** and click **Refresh**
4. Go to **Extensions → Extensions → Payments**, find **Stripe Payment** and click **Install**
5. Click **Edit** and enter your API keys

## Configuration

| Field | Description |
|---|---|
| Publishable Key | Your `pk_live_...` or `pk_test_...` key — safe to expose in the browser |
| Secret Key | Your `sk_live_...` or `sk_test_...` key — never share or log this |
| Webhook Secret | Signing secret (`whsec_...`) from Stripe Dashboard → Webhooks |
| Order Status | Status applied to the order after a successful payment (default: Processing) |
| Test Mode | Informational toggle — actual test/live mode is determined by the key prefix |
| Status | Enable or disable the payment method |
| Sort Order | Display order relative to other payment methods |

## Webhook Setup

Webhooks are required for delayed payment methods (SEPA Direct Debit, Sofort, bank redirects). Without a configured webhook, orders paid via these methods will remain in *Pending* status indefinitely.

1. Go to **Stripe Dashboard → Developers → Webhooks → Add endpoint**
2. Set the endpoint URL to the value shown in the plugin settings page
3. Subscribe to these events:
   - `payment_intent.succeeded`
   - `payment_intent.payment_failed`
4. Copy the signing secret and paste it into the **Webhook Secret** field in OpenCart

The webhook URL follows this format:

```
https://yourdomain.com/index.php?route=extension/payment/stripe_webhook
```


## License

MIT
