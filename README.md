# RansHosting

RansHosting is a WordPress theme for managing hosting clients, services, billing, and renewal reminders.

## Find services in WordPress

Open **RansHosting → All Services** in the WordPress dashboard. Choose a client, service type, payment status, or renewal window, then click **Filter**. Filters can be combined (for example, select **Unpaid** and **Due within 45 days** to find upcoming invoices that need attention).

- **Expired** means the term ended before today in the site's timezone.
- **Due within 45 days** includes today and the date 45 calendar days from today.
- **No renewal date** includes services with a blank or missing term end date.
- **Unpaid** includes services with no saved payment status, matching the payment column.

Set the dropdowns back to their **All…** options and click **Filter** to clear them. Click **Term ends** or **Client** to change the sort order.

### WordPress smoke test

On a staging site, create services for two clients with paid, unpaid, and partial payments. Give them term end dates of yesterday, today, 45 days from today, and 46 days from today. Include a draft with no saved term date. Verify that each renewal filter returns the expected records, that payment and client filters narrow those results, and that the undated draft remains visible when sorting. Confirm the Clients screen and public portal are unaffected by the list filters.

## Renewals & Payments dashboard

Administrators can open **RansHosting → Renewals & Payments** for counts of overdue renewals, renewals due within 7 or 30 days, and unpaid services. Each card opens the corresponding filtered list of published services. Upcoming windows include today and the last day of the window; the 7-day count is also included in the 30-day count. Partial payments are not included in the Unpaid card and can be found using the **Partial** filter.

On staging, add published services due today and in 7, 8, 30, and 31 days. Check that each card matches the result total on its linked screen, including when the count is zero. Verify that drafts do not affect counts and that accounts without `manage_options` cannot open the dashboard.

## Bundle multiple items on one invoice

Edit a service and click **Add sub-service** in the **Sub-services** section to add separate hosting, domain, or email line items. Each line has its own type, plan name, billing cycle, email accounts, portal notes, term dates, and amount. The **Main Service Invoice, Dates & Payment** section totals the line items in one currency and holds the shared invoice number, payment status, and renewal payment link. Removing all line items restores standalone pricing. The main service end date still drives renewal filters and reminders; line-item dates do not schedule separate reminders.

On staging, add hosting at 1200.50 and a domain at 800.25. Save and reload to verify the total is 2000.75 in the editor, Services list, portal, and invoice. Remove both items and verify the standalone price returns. Also check that marking a bundled invoice paid uses its combined total.

The editor uses sub-service rows for plan details, email accounts, and portal notes. Existing standalone services still show their saved details until you add a sub-service. Adding rows does not delete the original standalone metadata.

## Portal caching

RansHosting marks portal responses private and non-cacheable. Client, service, and portal settings changes automatically request a homepage purge through WP Rocket or LiteSpeed Cache when available; multiple changes in one request queue one purge. Other integrations can use the `ranshosting_purge_portal_cache` action, which receives the portal URL.

After deploying this update, clear any existing page or CDN cache once. If a cache serves HTML before WordPress runs, configure it to bypass the portal URL, including its query-string variants; the theme cannot invalidate an independently managed host or CDN cache by itself. Static assets remain cacheable.
