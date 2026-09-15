# FacultyLens Real-Time Notification Event Matrix (STEP 48)

This matrix documents the complete catalog of real-time notification events in FacultyLens.
Every persisted notification dispatches a real-time `NotificationCreated` broadcast event over Laravel Reverb to the recipient's private WebSocket channel.

## WebSocket Architecture Summary

- **WebSocket Server:** Laravel Reverb (running on port 8085 / 8080)
- **Channel Format:** `private-users.{userId}.notifications`
- **Client Library:** Laravel Echo (`pusher-js` engine)
- **Authentication:** Sanctum-authenticated channel authorization (`POST /api/broadcasting/auth`)
- **Event Name:** `NotificationCreated` (`.NotificationCreated` in Echo)
- **Fail-Safe Guarantee:** Real-time broadcasting is executed inside a safe `try/catch` guard. If Reverb or Redis is temporarily unavailable, notification persistence in MySQL and core academic workflows are never interrupted. Polling fallback seamlessly activates if WebSocket disconnects.

---

## Complete Real-Time Event Catalog

| Domain Event | Notification Type | Category | Severity | Broadcast Channel | Action Route | Default Recipient Resolution | Deduplication Key |
|---|---|---|---|---|---|---|---|
| `AssessmentAnalysisCompleted` | `AI_ANALYSIS_COMPLETED` | `AI` | `SUCCESS` | `users.{userId}.notifications` | `/assessments/{a}/analysis` | Course members with `view_analysis` permission | `TYPE:analysis_report:{report_id}` |
| `AssessmentAnalysisFailed` | `AI_ANALYSIS_FAILED` | `AI` | `ERROR` | `users.{userId}.notifications` | `/assessments/{a}/analysis` | Course members with `run_analysis` permission | `TYPE:analysis_report:{report_id}:{YmdHi}` |
| `RecommendationsCreated` | `AI_RECOMMENDATION_CREATED` | `AI` | `INFO` | `users.{userId}.notifications` | `/assessments/{a}/analysis` | Course members with `approve_recommendation` (OWNER/EDITOR) | `TYPE:analysis_report:{report_id}` |
| `RubricGenerated` | `RUBRIC_GENERATED` | `AI` | `SUCCESS` | `users.{userId}.notifications` | `/assessments/{a}` | Requesting faculty member | `TYPE:rubric:{rubric_id}` |
| `RubricGenerationFailed` | `RUBRIC_GENERATION_FAILED` | `AI` | `ERROR` | `users.{userId}.notifications` | `/assessments/{a}` | Requesting faculty member | `TYPE:question:{question_id}:{YmdHi}` |
| `QuestionGenerationCompleted` | `QUESTION_GENERATION_COMPLETED` | `AI` | `SUCCESS` | `users.{userId}.notifications` | `/courses/{c}/question-generator?request={r}` | Request owner | `TYPE:question_generation_request:{request_id}` |
| `QuestionGenerationFailed` | `QUESTION_GENERATION_FAILED` | `AI` | `ERROR` | `users.{userId}.notifications` | `/courses/{c}/question-generator?request={r}` | Request owner | `TYPE:question_generation_request:{request_id}` |
| `AssessmentVersionStatusChanged(CREATED)` | `ASSESSMENT_VERSION_CREATED` | `ASSESSMENT` | `INFO` | `users.{userId}.notifications` | `/assessments/{a}/versions/{v}` | Course editors (excluding actor) | `TYPE:assessment_version:{version_id}` |
| `AssessmentVersionStatusChanged(RESTORED)` | `ASSESSMENT_VERSION_RESTORED` | `ASSESSMENT` | `INFO` | `users.{userId}.notifications` | `/assessments/{a}/versions/{v}` | Course editors (excluding actor) | `TYPE:assessment_version:{version_id}` |
| `AssessmentVersionStatusChanged(SUBMITTED)` | `REVIEW_ASSIGNED` | `REVIEW` | `INFO` | `users.{userId}.notifications` | `/assessments/{a}/versions/{v}` | Course reviewers / editors (excluding actor) | `TYPE:assessment_version:{version_id}:{submitted_at}` |
| `AssessmentVersionStatusChanged(APPROVED)` | `ASSESSMENT_VERSION_APPROVED` | `ASSESSMENT` | `SUCCESS` | `users.{userId}.notifications` | `/assessments/{a}/versions/{v}` | Course editors including creator | `TYPE:assessment_version:{version_id}` |
| `ReviewCompleted` | `REVIEW_COMPLETED` | `REVIEW` | `SUCCESS` | `users.{userId}.notifications` | `/assessments/{a}/versions/{v}` | Assessment version creator | `TYPE:assessment_version:{version_id}` |
| `AssessmentVersionStatusChanged(FINALIZED)` | `ASSESSMENT_VERSION_FINALIZED` | `ASSESSMENT` | `SUCCESS` | `users.{userId}.notifications` | `/assessments/{a}/versions/{v}` | Course members with edit access | `TYPE:assessment_version:{version_id}` |
| `AssessmentVersionStatusChanged(ARCHIVED)` | `ASSESSMENT_VERSION_ARCHIVED` | `ASSESSMENT` | `INFO` | `users.{userId}.notifications` | `/assessments/{a}/versions/{v}` | Course editors (excluding actor) | `TYPE:assessment_version:{version_id}` |
| `CollaborationInvitationCreated` | `COLLABORATION_INVITATION` | `COLLABORATION` | `INFO` | `users.{userId}.notifications` | `/collaboration/invitations` | Invited faculty user | `TYPE:course_collaboration_invitation:{invitation_id}` |
| `CollaborationInvitationAccepted` | `COLLABORATION_ACCEPTED` | `COLLABORATION` | `SUCCESS` | `users.{userId}.notifications` | `/courses/{c}/collaboration` | Inviting faculty user | `TYPE:course_collaboration_invitation:{invitation_id}` |
| `CollaborationInvitationDeclined` | `COLLABORATION_REJECTED` | `COLLABORATION` | `INFO` | `users.{userId}.notifications` | `/courses/{c}/collaboration` | Inviting faculty user | `TYPE:course_collaboration_invitation:{invitation_id}` |
| `CollaboratorRemoved` | `COLLABORATION_REMOVED` | `COLLABORATION` | `WARNING` | `users.{userId}.notifications` | `/collaboration/invitations` | Removed collaborator | `TYPE:course:{course_id}:{timestamp}` |
| `CollaboratorRoleChanged` | `COLLABORATION_ROLE_CHANGED` | `COLLABORATION` | `INFO` | `users.{userId}.notifications` | `/courses/{c}/collaboration` | Affected collaborator | `TYPE:course:{course_id}:{to_role}:{timestamp}` |
| `CommentCreated` (reply) | `COMMENT_CREATED` | `COLLABORATION` | `INFO` | `users.{userId}.notifications` | `/courses/{c}/collaboration?comment={id}` | Thread participants (excluding actor) | `TYPE:collaboration_comment:{comment_id}` |
| `CommentCreated` (@mention) | `MENTION_RECEIVED` | `COLLABORATION` | `INFO` | `users.{userId}.notifications` | `/courses/{c}/collaboration?comment={id}` | Mentioned course members | `TYPE:collaboration_comment:{comment_id}` |
| `GradingCompleted` | `GRADING_COMPLETED` | `GRADING` | `SUCCESS` | `users.{userId}.notifications` | `/submissions/{s}` | Requesting faculty user | `TYPE:ai_grading_result:{result_id}` |
| `GradingFailed` | `GRADING_FAILED` | `GRADING` | `ERROR` | `users.{userId}.notifications` | `/submissions/{s}` | Requesting faculty user | `TYPE:ai_grading_result:{result_id}` |
| `InterGraderReviewRequired` | `INTER_GRADER_REVIEW_REQUIRED` | `GRADING` | `WARNING` | `users.{userId}.notifications` | `/assessments/{a}/submissions` | Members with `view_student_data` | `TYPE:assessment:{a}:q{q}:{Ymd}` |
| `PerformanceAnalysisCompleted` | `PERFORMANCE_ANALYSIS_COMPLETED` | `PERFORMANCE` | `SUCCESS` | `users.{userId}.notifications` | `/assessments/{a}/analysis` | Members with `view_student_data` | `TYPE:performance_analysis_run:{run_id}` |
| `LearningGapDetected` | `LEARNING_GAP_DETECTED` | `PERFORMANCE` | `WARNING` | `users.{userId}.notifications` | `/assessments/{a}/analysis` | Members with `view_student_data` | `TYPE:performance_analysis_run:{run_id}` |
| `PerformanceAnalysisFailed` | `PERFORMANCE_ANALYSIS_FAILED` | `PERFORMANCE` | `ERROR` | `users.{userId}.notifications` | `/assessments/{a}/analysis` | Members with `view_student_data` | `TYPE:performance_analysis_run:{run_id}:{YmdHi}` |
| `ReportGenerated` | `REPORT_GENERATED` | `REPORT` | `SUCCESS` | `users.{userId}.notifications` | `/reports/{id}` | Report requester | `TYPE:institutional_report:{report_id}` |
| `ReportGenerationFailed` | `REPORT_GENERATION_FAILED` | `REPORT` | `ERROR` | `users.{userId}.notifications` | `/reports/{id}` | Report requester | `TYPE:institutional_report:{report_id}` |
| `FacultyFeedbackCreated` | `FACULTY_FEEDBACK_RECEIVED` | `FEEDBACK` | `INFO` | `users.{userId}.notifications` | `/assessments/{a}/analysis` | Members with `approve_recommendation` | `TYPE:recommendation:{rec_id}:{submitter_id}:{YmdHi}` |
| `SecurityAlertRaised` (login attempts) | `SECURITY_ALERT` | `SECURITY` | `CRITICAL` | `users.{userId}.notifications` | `/settings` | Account owner (mandatory, cannot mute) | `SECURITY_ALERT:user:{id}:failed_logins:{YmdH}` |
| `SecurityAlertRaised` (password changed) | `SECURITY_ALERT` | `SECURITY` | `CRITICAL` | `users.{userId}.notifications` | `/settings` | Account owner (mandatory, cannot mute) | `SECURITY_ALERT:user:{id}:password_changed:{ts}` |
| `SystemAlertRaised` | `SYSTEM_ALERT` | `SYSTEM` | `WARNING` / `CRITICAL` | `users.{userId}.notifications` | none / custom | Target user(s) or all administrators | Custom operator key |

---

## Client UI State Transitions

When any of the above events arrive over WebSocket:

1. **Unread Count & Badge:**
   - Increments by `+1` instantly (`🔔 3` $\rightarrow$ `🔔 4`).
   - Deduplication check prevents replay increments if the event has already been registered.
2. **Notification Bell Dropdown:**
   - Prepend the new notification row at the top of the list in real-time.
   - Shows bold title, category badge, relative timestamp, and action link.
3. **Notification Toast:**
   - Non-intrusive toast pops up at bottom-right of viewport for unread items.
   - Auto-dismisses after 6 seconds, or on manual close / click.
4. **Notifications Page (`/notifications`):**
   - Active inbox table re-renders the new notification without requiring manual refresh.
5. **Mark Read Transitions:**
   - Single item read: `🔔 4` $\rightarrow$ `🔔 3`.
   - Mark all read: `🔔 3` $\rightarrow$ `🔔 0`.
   - State instantly propagates across all open browser tabs via `BroadcastChannel`.

