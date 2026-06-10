# Title

Lessons Learned Implementing PostHog: Identity, User Journeys, and Reliable Analytics

---

# Content

As we've continued to expand analytics capabilities across our applications, one lesson has become increasingly clear: collecting events is easy, but collecting meaningful, *connected* data is much harder.

Over the past several months, we've implemented PostHog across multiple systems, including web applications, CRM integrations, and data warehouse environments. Along the way, we've learned several important lessons about user identification, event tracking, browser limitations, and building a reliable analytics architecture.

## Why Understanding User Journeys Matters

Every business wants to understand how customers interact with their products.

Questions such as:

- Which pages lead to conversions?
- Where do users abandon the signup process?
- Which features drive engagement?
- What actions are most common before a customer upgrades?

...can only be answered when analytics are properly implemented.

Capturing isolated events provides limited value. The real value comes from understanding the complete customer journey — from first visit through long-term engagement.

PostHog gives organizations the ability to visualize these journeys, identify bottlenecks, and make data-driven product decisions.

## JavaScript Tracking vs. Server-Side Tracking

One of the first decisions teams face is how events should be captured.

### JavaScript Tracking

Client-side tracking runs directly in the browser and is excellent for:

- Page views
- Button clicks
- Session recordings
- Heatmaps
- User interactions

Examples include:

- Landing page visits
- Navigation clicks
- Form interactions
- Dashboard usage

JavaScript tracking provides detailed behavioral insights but comes with limitations — particularly around ad blockers, privacy extensions, and browser restrictions (more on this below).

### Server-Side Tracking

Server-side tracking occurs within your application backend.

Examples include:

- Account creation
- Subscription purchases
- Metric creation
- Report generation
- CRM updates

These events represent business actions rather than browser interactions.

Because server-side events originate from trusted application infrastructure, they are generally more reliable and less susceptible to browser restrictions.

### The Best Approach

The most effective implementations use both.

Client-side tracking captures user behavior, while server-side tracking captures critical business events. Together, they create a complete picture of customer activity.

## Anonymous Users vs. Identified Users

Another critical concept is understanding anonymous and identified users.

### Anonymous Users

When a visitor first arrives, PostHog assigns an anonymous identifier (a `distinct_id`, also known as `$device_id`).

At this stage, we know:

- Pages viewed
- Actions performed
- Session activity

But we don't know *who* the person is.

### Identified Users

Once a visitor:

- Creates an account
- Logs in
- Completes a form
- Provides an email address

...we can identify them within PostHog using `posthog.identify()` (or the equivalent server-side `$identify` event).

This connects their historical anonymous activity with a real customer profile. Instead of seeing isolated events, we gain visibility into the complete customer journey — from the very first anonymous pageview through to becoming a lead.

## A Real-World Example: Identifying Leads at the Point of Form Submission

We recently put these principles into practice on a WordPress-based lead-generation site that uses two different form builders side by side. Every form submission was already being captured as an event in PostHog — but every submission was tied to an *anonymous* visitor ID, even when the form clearly contained the lead's name, email, and phone number.

The fix followed the exact pattern described above:

1. **Detect identifying information at the point of conversion.** When a form is submitted successfully, the integration scans the submitted fields for an email address — no special configuration needed per form.
2. **Call `identify()` only when needed.** If the visitor isn't already identified as that email, `posthog.identify()` is called (client-side), or an `$identify` event is sent (server-side) using the visitor's existing anonymous ID via `$anon_distinct_id`.
3. **Merge anonymous history into the new profile.** Because the anonymous ID is passed along, all of that visitor's prior pageviews, UTM parameters, and "submit attempt" events are merged into their new, identified profile.
4. **Keep both client- and server-side paths covered.** One form builder is tracked client-side via the browser; the other is tracked server-side via a WordPress hook. Both now follow the same identification logic, just implemented on the appropriate side of the request.

The result: as soon as a lead fills out a form, PostHog automatically creates or updates a Person profile with their email (and name/phone, if provided), and their entire journey — from first anonymous visit to converted lead — becomes visible in one place. No manual tagging, no per-form configuration, and no duplicate profiles for repeat submissions.

## The Importance of a Universal User Identifier

One of the biggest lessons we learned is that every system should reference the same user identifier.

Organizations often maintain data in multiple systems:

- Application databases
- Salesforce
- HubSpot
- Snowflake
- PostHog

Without a shared identifier, customer data becomes fragmented.

We recommend establishing a universal user identifier that travels across every platform. For example:

> Application User ID → PostHog Distinct ID → CRM Contact ID → Snowflake Customer Key

In our example above, the lead's **email address** serves as that shared key — it's the same identifier PostHog uses as the `distinct_id`, and the same value that flows into the CRM record.

This creates a consistent customer profile and simplifies reporting, troubleshooting, and analytics.

## Ensuring People and Events Remain Connected

Capturing events alone is not enough. Every event should be associated with a known person whenever possible.

Poor implementations often result in thousands of events that can't be tied back to actual customers.

Strong implementations ensure that:

- Users are identified as early as possible
- Events reference the correct user identifier
- Historical activity remains connected after identification
- The same visitor is never accidentally split into multiple disconnected profiles

This dramatically improves reporting quality and customer visibility — and it's worth getting right the first time, since merging fragmented profiles after the fact is far harder than identifying correctly from the start.

## Planning for Browser Blocking

Modern browsers increasingly prioritize privacy.

Users may have:

- Ad blockers
- Privacy extensions
- Corporate firewalls
- Browser-level tracking protections

As a result, client-side analytics may occasionally fail.

Organizations should plan for this reality. Critical business events should also be captured server-side whenever possible.

Examples include:

- Form submissions
- Account registrations
- Purchases
- Subscription changes
- CRM updates

This ensures important data remains available even when browser-based tracking is blocked.

## Integrating Analytics with CRM Systems

Analytics become significantly more valuable when connected to customer relationship management systems.

PostHog supports integrations and pipelines that allow organizations to connect analytics data with platforms such as:

- Salesforce
- HubSpot
- Snowflake
- Data Warehouses

These integrations help teams:

- Understand customer behavior
- Improve lead qualification
- Enhance customer support
- Build more accurate reporting

Instead of treating analytics and CRM data as separate systems, organizations can create a unified customer view.

## Final Thoughts

PostHog is much more than an event tracking platform.

When implemented correctly, it becomes a central component of a broader customer intelligence strategy.

Our biggest takeaways are simple:

1. Understand complete user journeys.
2. Identify users as early as possible.
3. Maintain a consistent identifier across systems.
4. Use both client-side and server-side tracking.
5. Plan for browser blocking.
6. Integrate analytics with CRM and data warehouse platforms.

Organizations that follow these principles build more reliable analytics, better customer visibility, and ultimately make better business decisions.
