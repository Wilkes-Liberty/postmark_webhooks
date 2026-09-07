# Operator accessibility verification

Issue #3621236. Optional follow-up after stable release; not a blocker for
#3621224.

## Scope

Alpha2 verified keyboard, focus, label and narrow-screen behavior in Stark.
This follow-up checks the operator forms in Claro, Drupal core's supported
administration theme, and keeps focused HTTP regression coverage.

The original issue named six forms: settings, policy preview, suppression
inspector, hard-bounce recovery, recipient export and history erasure. The
MessageID timeline from #3621234 is included because it is now an operator
report on the same permission.

## Environment

| Item | Value |
| --- | --- |
| Administration theme | Claro (Drupal core) |
| Automated browser | Mink/`BrowserTestBase` (no JavaScript execution) |
| PHPUnit coverage | `PostmarkOperatorAccessibilityTest` plus existing form tests |
| Live screen reader | Not claimed. VoiceOver, NVDA and JAWS were not driven in this change. |
| Other admin themes | Gin and Olivero were not certified |

Mink does not run `js/operator.js`. Result-region markup (`role="region"`,
`tabindex="-1"`) is asserted; moving focus after a successful rebuild is
implemented for real browsers and remains a live keyboard check.

## Test steps (automated)

1. Anonymous GET of settings, preview, inspector, timeline and export returns
   403. Recovery and erasure also return 403 without their permissions.
2. In Claro, each form exposes a labeled control, associated instructions
   (`role="note"` plus `aria-describedby`) and a CSRF token.
3. Invalid preview and timeline source filters associate errors with the
   source fields. Results are not shown until the form is valid.
4. Successful inspector, preview and timeline lookups render a named result
   region with a `role="status"` summary.
5. Timeline GET query strings still do not load results. Repeat inspector
   lookup stays on the form path.
6. Recovery confirmation uses a destructive control, a Cancel link, and
   rejects a forged token without writing an audit row.
7. Erasure review shows a destructive confirm control and Cancel. Cancel
   returns to the recipient field without deleting suppression evidence.

Narrow-width CSS (`max-width: 100%`, wrapping, full-width inputs below
48rem) ships in `css/operator.css`. Viewport and 200% zoom remain a live
browser check; Mink has no layout engine.

## Findings fixed

- Operator instructions were unassociated `#markup`. They are now a `note`
  landmark referenced from the form.
- Inspector, preview and timeline results had no named region or status
  summary, so rebuilds were easy to miss.
- Timeline filter errors used the messenger instead of field association.
- Empty timeline lookups announced two `status` regions. The archive caveat
  is now a `note`; the empty or summary line is the status.
- Erasure confirmation had no Cancel control and did not mark Confirm as
  destructive.
- Recovery Confirm used the default primary button type.
- Result containers had no keyboard focus target.

Permission, CSRF, privacy and confirmation boundaries are unchanged: lookup
identifiers stay in POST bodies, export still omits provider free text, and
recovery still compares the submitted evidence snapshot.

## Remaining limitations

- Live screen-reader certification (VoiceOver, NVDA, JAWS) is still outstanding.
- JavaScript focus-after-rebuild is not exercised by Mink HTTP tests.
- File-download announcements after recipient export depend on the browser
  and are often silent; the form states that limitation.
- Claro is the verified administration theme. Custom or contrib admin themes
  are not certified.
- 375×812 and 200% zoom were not re-measured in a real browser in this
  change; CSS continues to cap input and details width.
