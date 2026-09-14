# FacultyLens Email Event Matrix

Every row is produced by an existing domain event → `app/Listeners/Notifications/*` → `NotificationService::notify()`.
The **Email** column is the default when the recipient has never chosen (`config/email.php → default_email_enabled`);
faculty can change it per type in *Settings → Notifications & Email Preferences* unless the row is marked
**mandatory**. **In-App** follows STEP 47 (SECURITY/SYSTEM mandatory).

Subjects are prefixed `FacultyLens — `. Templates live in `backend/resources/views/emails/`. Recipients are always
resolved through the collaboration authorization model (`NotificationRecipientResolver`), never from request input.

| Event (notification type) | Recipient | Subject | Template | Category | Email (default) | In-App |
|---|---|---|---|---|---|---|
| AI analysis completed (`AI_ANALYSIS_COMPLETED`) | Assessment owner + collaborators with `view_analysis` | Assessment Analysis Completed | `assessment-analysis-completed` | AI | Yes | Yes |
| AI analysis failed (`AI_ANALYSIS_FAILED`) | Owner + `run_analysis` | Assessment Analysis Failed | `assessment-analysis-failed` | AI | Yes | Yes |
| Recommendations created (`AI_RECOMMENDATION_CREATED`) | Owner + `approve_recommendation` | New Assessment Recommendations | `recommendations-created` | AI | Yes | Yes |
| Rubric generated (`RUBRIC_GENERATED`) | Requesting faculty | Rubric Draft Ready | `rubric-generated` | AI | Yes | Yes |
| Rubric generation failed (`RUBRIC_GENERATION_FAILED`) | Requesting faculty | Rubric Generation Failed | `notification` | AI | Yes | Yes |
| Question generation completed (`QUESTION_GENERATION_COMPLETED`) | Requesting faculty | Question Drafts Ready | `question-generation-completed` | AI | Yes | Yes |
| Question generation failed (`QUESTION_GENERATION_FAILED`) | Requesting faculty | Question Generation Failed | `notification` | AI | Yes | Yes |
| Assessment version created (`ASSESSMENT_VERSION_CREATED`) | `edit_assessment` collaborators (not actor) | Assessment Version Created | `assessment-version` | ASSESSMENT | No | Yes |
| Assessment version approved (`ASSESSMENT_VERSION_APPROVED`) | `edit_assessment` collaborators | Assessment Version Approved | `assessment-version` | ASSESSMENT | No | Yes |
| Assessment version finalized (`ASSESSMENT_VERSION_FINALIZED`) | `edit_assessment` collaborators | Assessment Version Finalized | `assessment-version` | ASSESSMENT | No | Yes |
| Assessment version archived (`ASSESSMENT_VERSION_ARCHIVED`) | `edit_assessment` collaborators (not actor) | Assessment Version Archived | `assessment-version` | ASSESSMENT | No | Yes |
| Assessment version restored (`ASSESSMENT_VERSION_RESTORED`) | `edit_assessment` collaborators | — | — | ASSESSMENT | **Never** | Yes |
| Collaboration invitation (`COLLABORATION_INVITATION`) | Invitee (existing account) | Collaboration Invitation | `collaboration-invitation` | COLLABORATION | Yes | Yes |
| Invitation accepted (`COLLABORATION_ACCEPTED`) | Inviter / course owner | Collaboration Invitation Accepted | `notification` | COLLABORATION | Yes | Yes |
| Invitation declined (`COLLABORATION_REJECTED`) | Inviter / course owner | Collaboration Invitation Declined | `notification` | COLLABORATION | Yes | Yes |
| Collaborator removed (`COLLABORATION_REMOVED`) | Removed collaborator | Collaboration Access Removed | `notification` | COLLABORATION | Yes | Yes |
| Role changed (`COLLABORATION_ROLE_CHANGED`) | Affected collaborator | — | — | COLLABORATION | **Never** | Yes |
| Comment created (`COMMENT_CREATED`) | Course participants (not author) | New Collaboration Comment | `notification` | COLLABORATION | Yes | Yes |
| Mention received (`MENTION_RECEIVED`) | Mentioned faculty | You Were Mentioned | `notification` | COLLABORATION | Yes | Yes |
| Review assigned (`REVIEW_ASSIGNED`) | Reviewers (`edit_assessment`, not submitter) | Review Assigned | `review-assigned` | REVIEW | Yes | Yes |
| Review completed (`REVIEW_COMPLETED`) | Version submitter | Review Completed | `notification` | REVIEW | Yes | Yes |
| Grading completed (`GRADING_COMPLETED`) | Grading faculty | Grading Suggestion Ready | `notification` | GRADING | Yes | Yes |
| Grading failed (`GRADING_FAILED`) | Grading faculty | Grading Could Not Be Completed | `notification` | GRADING | Yes | Yes |
| Inter-grader review required (`INTER_GRADER_REVIEW_REQUIRED`) | Authorized faculty (`view_student_data`) | Grading Consistency Review Recommended | `grading-review-required` | GRADING | Yes | Yes |
| Performance analysis completed (`PERFORMANCE_ANALYSIS_COMPLETED`) | Authorized faculty | Performance Analysis Ready | `performance-analysis-ready` | PERFORMANCE | Yes | Yes |
| Performance analysis failed (`PERFORMANCE_ANALYSIS_FAILED`) | Authorized faculty | Performance Analysis Failed | `notification` | PERFORMANCE | Yes | Yes |
| Learning gap detected (`LEARNING_GAP_DETECTED`) | Authorized faculty | Learning Outcome Review Recommended | `learning-gap-detected` | PERFORMANCE | Yes | Yes |
| Report generated (`REPORT_GENERATED`) | Requester | Your Report Is Ready | `report-ready` | REPORT | Yes | Yes |
| Report generation failed (`REPORT_GENERATION_FAILED`) | Requester | Report Generation Failed | `notification` | REPORT | Yes | Yes |
| Faculty feedback received (`FACULTY_FEEDBACK_RECEIVED`) | Assessment owner (not submitter) | New Feedback Received | `notification` | FEEDBACK | Yes | Yes |
| Security alert (`SECURITY_ALERT`) — failed sign-ins, password changed, password reset | Account owner | Security Alert | `security-alert` | SECURITY | **Mandatory** | **Mandatory** |
| System alert (`SYSTEM_ALERT`) | Admins / given users | System Notice | `notification` | SYSTEM | Yes | **Mandatory** |

### Authentication e-mails (not notification types — preferences do not apply)

| Event | Recipient | Subject | Template | Trigger |
|---|---|---|---|---|
| Password reset requested (`PASSWORD_RESET`) | Account owner | Reset Your Password | `reset-password` | `POST /api/auth/forgot-password` → password broker → `User::sendPasswordResetNotification()` |
| Operator test (`TEST_EMAIL`) | Caller (admins: any address) | Test Email | `test-email` | `POST /api/email/test`, `php artisan email:test` |

### Content rules applied to every row

* Subject lines carry no student names, identifiers, marks or private comments.
* Bodies contain the assessment/course title at most; performance and grading e-mails are aggregate-only.
* Reports are never attached — the CTA opens the authenticated application.
* AI outputs are described as drafts/recommendations “for faculty review”; failures state that no academic data was
  changed; grading-consistency mail never names a grader as wrong.
* Every template carries the common header (*FacultyLens — AI-Powered Academic Decision Support*) and footer
  (*You are receiving this email because of activity associated with your FacultyLens account.*, preferences link
  where applicable).
