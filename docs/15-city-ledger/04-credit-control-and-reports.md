# Credit control and city ledger reports

## Services

- `CreditExposure`: open AR balance + balances of in-house folios billed to the company.
- `AgingReport`: buckets 0-30, 31-60, 61-90, 90+ days past due.
- `StatementBuilder`: statement of account per company, rendered as PDF with dompdf.

## Rules

- Credit exposure is checked at booking, check-in and direct-bill settlement.
- Going over the limit blocks the action unless the user has `company.credit-override`.

## Out of scope

Payment submissions by companies, payment intents, agent payment holds, MyInvois e-invoicing.

The agent portal is planned as the last module: see [Agent portal](../23-agent-portal/01-agent-portal.md).
