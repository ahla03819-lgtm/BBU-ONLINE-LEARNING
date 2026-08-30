# Phase 11 Results Inertia contracts

Phase 11A provides presentation-only pages. Phase 11B adds reporting-period routes and read-only server props, but intentionally creates no result snapshots, calculation, publication, or mutation endpoint. Later backend stages must provide authorization-scoped props rather than accepting any of these identifiers from the browser.

## `Results/Index`

- `filters`: selected academic-year, reporting-period, class, and subject values; `academic_years` is optional.
- `reportingPeriods`, `classes`, `subjects`: selectable, authorized options with public identifiers and display names.
- `results`: staff-visible rows containing a public result identifier, student display name, subject display name, `score_display`, publication state, formatted update timestamp, and an authorized `review_url` when applicable. Phase 11B supplies an empty collection.
- `summary`: counts for students, ready-for-review, published, and needs-attention.

## `Results/Show`

- `context`: authorized class, subject, academic-year, reporting-period, and review state. It may include an authorized `index_url`.
- `students`: only roster members in the authorized class-subject context, with display-safe score, percentage, outcome, attendance, and review fields.
- `attendanceSummary`, `publicationState`.
- `capabilities`: server-owned booleans for `review`, `publish`, and `correct`.

## `Results/MyResults`

- `academicYear`, `periods`, `selectedPeriod`.
- `results`: only the authenticated student's published, display-safe subject results. Optional `report_card_url` must be server-generated and authorization-scoped.
- `attendanceSummary`: only the authenticated student's authorized aggregate display values.

## `Results/ReportCard`

- `student`, `academicYear`, `reportingPeriod`, `schoolClass`.
- `results`: only the authenticated student's published subjects with display-safe score, percentage, and outcome fields.
- `attendanceSummary`: only the authenticated student's authorized aggregate display values.

Phase 11B routes are `GET /results`, `GET /results/{reportingPeriod}/school-classes/{schoolClass}/class-subjects/{classSubject}`, `GET /my-results`, and `GET /my-results/reporting-periods/{reportingPeriod}/school-classes/{schoolClass}`. Management routes are limited to authorized reporting-period create, update, and lifecycle transitions.

No contract includes staff identifiers, correction reasons, internal database IDs, other students' data for student pages, grading formulas, or result mutation URLs. GET routes perform click-time policy checks; future result mutations must remain separate, server-authoritative actions.
