/**
 * STEP 34: Faculty Collaboration types. `permissions` is a server-provided UX hint; the API is authoritative.
 */
export type CollaborationRole = 'OWNER' | 'EDITOR' | 'REVIEWER' | 'VIEWER';
export type AssignableRole = Exclude<CollaborationRole, 'OWNER'>;
export type MembershipStatus = 'PENDING' | 'ACTIVE' | 'DECLINED' | 'REVOKED';
export type InvitationStatus = 'PENDING' | 'ACCEPTED' | 'DECLINED' | 'REVOKED' | 'EXPIRED';
export type CommentStatus = 'ACTIVE' | 'RESOLVED' | 'DELETED';
export type CommentableType = 'course' | 'assessment' | 'question' | 'analysis_report' | 'recommendation' | 'generated_question' | 'rubric' | 'learning_outcome' | 'document';

export type PermissionKey =
  | 'view' | 'view_documents' | 'download_documents' | 'upload_documents' | 'manage_documents' | 'edit_course' | 'delete_course'
  | 'create_assessment' | 'edit_assessment' | 'delete_assessment' | 'edit_question' | 'view_analysis' | 'run_analysis'
  | 'approve_recommendation' | 'generate_questions' | 'approve_generated_question' | 'approve_rubric' | 'comment'
  | 'view_student_data' | 'manage_collaborators' | 'transfer_ownership';

export type Permissions = Partial<Record<PermissionKey, boolean>>;

export const ROLE_LABELS: Record<CollaborationRole, string> = { OWNER: 'Owner', EDITOR: 'Editor', REVIEWER: 'Reviewer', VIEWER: 'Viewer' };
export const ROLE_DESCRIPTIONS: Record<AssignableRole, string> = {
  EDITOR: 'Edit course content, assessments, questions and drafts; approve recommendations and generated questions.',
  REVIEWER: 'View shared resources and analysis; comment and suggest changes. Cannot modify official content.',
  VIEWER: 'View authorized resources and activity only.',
};

export interface UserRef { id: number; name: string; email?: string | null; department?: string | null }

export interface Collaborator {
  id: number;
  user: UserRef | null;
  role: CollaborationRole;
  status: MembershipStatus;
  invited_by: UserRef | null;
  invited_at: string | null;
  accepted_at: string | null;
}

export interface Invitation {
  id: number;
  course: { id: number; course_code: string | null; course_name: string | null } | null;
  invited_email: string | null;
  invited_by: UserRef | null;
  role: AssignableRole;
  status: InvitationStatus;
  message: string | null;
  expires_at: string | null;
  created_at: string | null;
  accept_url?: string;
}

export interface CollaborationOverview {
  course: { id: number; course_code: string | null; course_name: string | null };
  owner: UserRef | null;
  current_user: { id: number; role: CollaborationRole | null };
  permissions: Permissions;
  collaborators: Collaborator[];
  pending_invitations: Invitation[];
  roles: AssignableRole[];
  role_matrix: Record<string, CollaborationRole[]>;
}

export interface CollaborationComment {
  id: number;
  course_id: number;
  commentable_type: CommentableType;
  commentable_id: number;
  parent_id: number | null;
  body: string;
  mentions: number[];
  status: CommentStatus;
  author: UserRef | null;
  resolved_by: UserRef | null;
  resolved_at: string | null;
  edited_at: string | null;
  created_at: string | null;
  replies: CollaborationComment[];
  reply_count: number | null;
}

export interface ActivityItem {
  id: number;
  action: string;
  entity_type: string | null;
  entity_id: number | null;
  actor: UserRef | null;
  summary: string;
  metadata: Record<string, unknown>;
  created_at: string | null;
}

export interface PageMeta { current_page: number; last_page: number; per_page?: number; total: number; can_comment?: boolean; unread_count?: number }

export interface CollaborationSummary {
  shared_courses_count: number;
  shared_courses: { id: number; course_code: string | null; course_name: string | null; role: CollaborationRole }[];
  pending_invitations_count: number;
  pending_invitations: Invitation[];
  unresolved_discussions_count: number;
  unread_notifications_count: number;
}

export interface AppNotification {
  id: string;
  read_at: string | null;
  created_at: string | null;
  event?: string;
  title?: string;
  body?: string;
  course_id?: number;
  course_code?: string;
  course_name?: string;
  actor_name?: string;
  url?: string;
}

export interface MentionableUser { id: number; name: string; role: CollaborationRole }
