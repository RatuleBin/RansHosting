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
