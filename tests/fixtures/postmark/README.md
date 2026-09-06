# Synthetic Postmark webhook fixtures

These fixtures follow the field shapes in Postmark's official examples, with
synthetic example.com mailboxes, identifiers, descriptions and fixed past dates.
They are local contract inputs; no provider account or real email is involved.

- [Bounce](https://postmarkapp.com/developer/webhooks/bounce-webhook)
- [Spam complaint](https://postmarkapp.com/developer/webhooks/spam-complaint-webhook)
- [Subscription change](https://postmarkapp.com/developer/webhooks/subscription-change-webhook)

The subscription fixture represents a manual/recipient suppression with nullable
MessageID. Fractional timestamps exercise the documented seven-digit form.
