# SMTPing Email Verifier for WHMCS

WHMCS addon module that verifies email addresses with [SMTPing](https://smtping.com).

- **Checkout**: new clients with an invalid, spamtrap, disposable or other risky address cannot place an order.
- **Registration and email changes**: the client area refuses risky addresses with your own error message.
- **Existing clients**: start a bulk verification from the module page, then see every client with a risky address, linked to the client profile.
- **No surprise costs**: results are stored for 30 days, so an address is never paid for twice. Existing clients who log in are not checked again.
- Fails open: if SMTPing does not answer within the timeout, the address goes through.

Requires WHMCS 8.0 or later, PHP 7.4 or later with cURL.

## Install

1. Download `smtping-whmcs.zip` from the [latest release](https://github.com/smtping/smtping-whmcs/releases/latest).
2. Upload the `modules/addons/smtping` folder into the `modules/addons` folder of your WHMCS installation.
3. In WHMCS, open **System Settings > Addon Modules**, find **SMTPing Email Verifier** and click **Activate**.
4. Click **Configure**, paste your API key from the [SMTPing dashboard](https://app.smtping.com), choose the checks, give access to your admin role and save.
5. Open **Addons > SMTPing Email Verifier** to verify your existing clients.

## Bands

| band | statuses | default action |
| --- | --- | --- |
| `safe` | valid, alias | accepted |
| `avoid` | invalid, spamtrap, disposable, blacklisted, complainer, spambot, inbox_full | blocked |
| `judgement` | catch_all, unknown, role and others | accepted (blocked if you choose "Avoid and judgement") |

## Credits

1 credit per verified address; unknown results are never charged. Every SMTPing account gets 25 free credits per day. See [pricing](https://smtping.com/pricing).

## Links

- [SMTPing API documentation](https://smtping.com/docs)
- Support: support@smtping.com
- Blog: [SMTPedia](https://smtpedia.com)

MIT License
