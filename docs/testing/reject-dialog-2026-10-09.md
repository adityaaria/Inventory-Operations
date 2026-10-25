# Reject reason dialog — 9 October 2026

User request: the reject reason should be asked in a pop-up dialog that opens when the user clicks Reject.

**Change.**
- **Sales order (PendingApproval, Admin):** the inline "Rejection reason" form was replaced by a **Reject Order** button next to Approve. It opens a dialog with a required reason (max 500 characters), a Cancel button and a red **Reject Order** submit.
- **Stock proposal (reviewer):** Reject opens the same kind of dialog (`decision=Rejected`). The inline field is now labelled "Approval reason" and is used only for Approve.
- **Shared partial:** `views/partials/reason-dialog.php`, built on the existing dialog component (`dialog.js`: Escape, backdrop click, focus trap). The dialog focuses the reason field when opened.
- **No second confirmation:** the dialog form carries `data-skip-confirm`, because the dialog itself is the confirmation step. Without it `forms.js` would ask "Confirm reject order?" a second time.
- **Server errors:** an invalid reason (empty or too long) returns 422 with the dialog reopened, the message inside it and the typed text kept (`SalesOrderController::reject`, `BusinessOperationController::decide`). If the document is no longer pending, the message is shown on the page instead. Previously an invalid SO reject reached the generic error page.
- **Button style:** `button[type="submit"].button-danger` keeps the red style.
- **Test double:** `InMemoryBusinessOperationRepository` now returns the same row shape as MySQL (`decision_reason`, `posted_by`, item `sku`/`product_name`), which removed 4 PHPUnit warnings.

**Tests.**
- **Unit:** `SalesOrderControllerTest` (dialog closed on load; invalid reason reopens it with message and text, status unchanged; valid reason cancels and shows the reason); `BusinessOperationControllerTest` (proposal dialog, reopen on blank reason, reject); JS `modal-validation.test.js` (a `data-skip-confirm` form posts without a second confirmation).
- **Quality gate:** 435 tests / 3,644 assertions, PHPStan no errors, JavaScript 72.
- **Browser, Chrome at 1440 and 390 px (21 checks):** dialog hidden on load, no inline reason field, opens with focus in the reason field, fits the screen, red submit, Escape and Cancel close it, empty reason blocked, reject with reason cancels the SO and shows the reason, proposal rejected through the dialog, no page exceptions.
- **Regression:** business HTTP 76/76, browser business 50/50, drafts 18/18, UI sweep 16 pages, dialog e2e 110 checks, mobile 303 pages without errors, work queue 13/13, timeline 21/21.

localhost:8080 runs the new image.
