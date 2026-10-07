# Android Chrome / iPhone Safari acceptance

The user has both device families available. Physical execution is pending; do not substitute Chrome emulation evidence for these results.

Use a trusted HTTPS deployment on an isolated acceptance dataset. The local one-day TLS certificate used in automation is not a public device-ready certificate. Record device/model, OS/browser version, build commit, date, viewport/orientation and actual observed result for every row. A simple URL preview without login/interaction is not acceptance.

| Scenario | Android Chrome | iPhone Safari |
|---|---|---|
| Login, validation messages, secure authenticated navigation, logout blocks Back/reload/API | NOT RUN | NOT RUN |
| Dashboard / Reports: portrait and landscape, no document horizontal overflow | NOT RUN | NOT RUN |
| Products / users / all master lists: search/filter, pagination and local table scroll | NOT RUN | NOT RUN |
| Header Create/Import/Export usable; CSV file opens/downloads with complete scope | NOT RUN | NOT RUN |
| Drawer: open/close, outside tap, focus return, background cannot be interacted with | NOT RUN | NOT RUN |
| Create/edit dialogs: one header, keyboard open, scroll, reachable Cancel/Submit, 422 values retained | NOT RUN | NOT RUN |
| PO create/order/partial/full receipt; role-specific actions | NOT RUN | NOT RUN |
| SO create/submit/approve/issue; own-order scope and insufficient stock | NOT RUN | NOT RUN |
| Warehouse dashboard: Low Stock Rows visible without Inventory Value; count active product–warehouse pairs including zero | NOT RUN | NOT RUN |
| PO partial receipt → close remainder with reason → new receipt rejected, successful historical key replay preserved | NOT RUN | NOT RUN |
| SO PendingApproval → reject with reason; Sales sees reason only on own accessible orders | NOT RUN | NOT RUN |
| Replenishment shows stock/open supply/suggested quantity per warehouse; PO prefill still requires review | NOT RUN | NOT RUN |
| Timeline: PO/SO/operation history, long reasons, signed movements and related/original links fit; Sales own-SO restriction retained | NOT RUN | NOT RUN |
| Work Queue: correct role tasks, own Sales scope, filters/pagination/status badges, oldest-first age and detail links fit portrait/landscape | NOT RUN | NOT RUN |
| Multi-item proposal: add/remove products, duplicate prevention, retained 422 inputs, baseline unaffected by adding/removing rows; mobile keyboard/footer reachable | NOT RUN | NOT RUN |
| Count adjustment: propose → another Admin approve → post; stale baseline rejected without overwriting stock | NOT RUN | NOT RUN |
| Direct transfer: different warehouses, independent approval, posting once; repeated posting adds no movement | NOT RUN | NOT RUN |
| Supplier/customer return: original movement selected, goods-fit required for customer, over-return denied | NOT RUN | NOT RUN |
| POST dialogs/navigation: no repeated header, reachable footer, no browser errors after success/422 | NOT RUN | NOT RUN |
| Session expires while form open: sign-in feedback, no automatic mutation replay | NOT RUN | NOT RUN |
| Zoom / larger system text / VoiceOver or TalkBack / reduced motion | NOT RUN | NOT RUN |

Never perform stock-flow acceptance against the user's production dataset. Trainer defaults are the current documented behavior. Store real observations and screenshots as new dated evidence; mark a row PASS only after physical execution. Any failure gets route/device/reproduction steps and regression verification before closure.

## Result handoff

Record: device/model, OS, browser/version, date, deployed build/working-tree identifier, isolated dataset URL and scenario. For each scenario provide actual PASS/FAIL, observed result and evidence path. Keep the values NOT RUN until physically tested. Chrome emulation previously passed 31 business layout/flow checks; it is separate evidence. Stock scenarios require a WarehouseStaff proposer, a different Admin reviewer, Sales for visibility checks, and an isolated seeded dataset. Do not test against the application's live stock.
