# Error catalog

Thirty-three codes. The same code shows on screen, in the log and in the support report. `{placeholders}` are filled in at run time. Source of truth for the plugin: `includes/Domain/ErrorCode.php`; for the admin app: `src/api/errors.ts`.

| Code | Class | Message | What the person does | Retries |
|---|---|---|---|---|
| `SYNC-AUTH-001` | needs_person | {system} refused the sign-in. Check the user and the API key. | Edit the credentials and test again. The connection pauses and orders wait. | No |
| `SYNC-AUTH-002` | needs_person | The sign-in to {system} has expired. Reconnect. | Press Reconnect | No, items wait |
| `SYNC-NET-001` | retry | Couldn’t reach {system}, or it didn’t answer within 30 seconds. | Nothing, or check the connection | Yes |
| `SYNC-NET-002` | retry | {system} answered with a server error ({status}). | Nothing | Yes |
| `SYNC-RATE-001` | retry | {system} asked us to slow down. Waiting {n} seconds. | Nothing | Yes |
| `SYNC-MAP-001` | needs_person | Product {sku} was not found in {system}. | Create it in the system or map the SKU, then retry the group | No |
| `SYNC-MAP-002` | needs_person | Tax code not mapped for {country} {rate}. | Choose a code in Tax mapping, then retry | No |
| `SYNC-MAP-003` | needs_person | Customer email is missing on order #{order}. | Add the email or skip the order | No |
| `SYNC-VAL-001` | needs_person | Buyer tax number must be 15 digits for Saudi Arabia. | Correct the number on the order, then retry | No |
| `SYNC-VAL-002` | needs_person | {system} rejected a field: {field}. {reason} | Fix the field or the mapping, then retry | No |
| `SYNC-DUP-001` | handled | {system} already has this order. Linked to {ref}. | Nothing | n/a |
| `SYNC-STATE-001` | handled | The order changed while it waited. The latest version was sent. | Nothing | n/a |
| `SYNC-CFG-001` | needs_person | There is no live mapping version for {type}. | Publish a mapping version | No |
| `SYNC-CFG-002` | needs_person | The encryption key is missing from wp-config.php. | Add the line shown and reload | No |
| `SYNC-PAUSE-001` | info | Syncing is paused. Items wait in the queue. | Resume, or check the connection | n/a |
| `SYNC-RECON-001` | info | The nightly check found {n} differences. | Open Reconciliation | n/a |
| `SYNC-COMP-001` | needs_person | The tax authority rejected invoice {invoice}: {reason}. | Fix the cause in the system, report again | No |
| `SYNC-LOCK-001` | handled | The background task stopped while sending. The item was queued again. | Nothing | Yes |
| `SYNC-STK-001` | info | Stock for {sku} is lower than units already sold. | Accept the system quantity or hold | No |
| `SYNC-INB-001` | info | An inbound message was refused: the signature or time was wrong. | Check the secret | n/a |
| `SYNC-AUTH-003` | needs_person | The sign-in to {system} was cancelled or not completed. | Press Sign in and approve | No |
| `SYNC-AUTH-004` | needs_person | {system} does not allow this user to {action}. | Give the user the missing access in {system}, then test again | No |
| `SYNC-CFG-003` | needs_person | {system} has no database called {name}. | Check the name on the sign-in page | No |
| `SYNC-NET-003` | needs_person | The security certificate of {system} could not be checked. | Ask your host to fix the certificate | No |
| `SYNC-PRV-001` | info | The preview could not build this order: {reason}. | Fix the rule or the order, then preview again | n/a |
| `SYNC-AUTH-005` | needs_person | The key or secret for {system} ran out on {date}. | Make a new key in {system} and paste it under Connections | No |
| `SYNC-AUTH-006` | needs_person | Your {system} plan does not include outside access. | Change the plan, or switch the API on, then test again | No |
| `SYNC-AUTH-007` | needs_person | {system} does not know this app yet. | Finish the app setup inside {system}, then test again | No |
| `SYNC-EXP-001` | needs_person | The export file could not be written to the uploads folder. | Ask your host to make the uploads folder writable, then create the export again | No |
| `SYNC-EXP-002` | needs_person | {n} orders were left out of the export: {reason}. | Map the missing ledger or code, then export again | No |
| `SYNC-CFG-004` | needs_person | {system} connects with a key, not with a sign-in page. | Use the address and key form for this connection | No |
| `SYNC-REST-001` | info | Something went wrong on the server. The details are in the log. | Try again in a moment. If it keeps happening, copy the support report and ask for help | No |
| `SYNC-REST-002` | needs_person | The test email could not be sent. WordPress did not accept the message. | Check the email settings of this site, then send the test again | No |
