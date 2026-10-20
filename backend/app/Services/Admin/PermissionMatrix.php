<?php

namespace App\Services\Admin;

/**
 * Read-only description of what each FacultyLens role can do, with the place in code that enforces it.
 * FacultyLens uses fixed roles (no permission tables): this matrix documents the enforced behaviour rather than
 * driving it, so it cannot drift into granting anything.
 */
class PermissionMatrix
{
    public const ROLES = [
        'ADMIN' => [
            'label' => 'Administrator',
            'description' => 'The single configured administrator account (ADMIN role + ADMIN_EMAIL). Runs the admin control center.',
            'assignable' => false,
            'protected' => true,
        ],
        'DEPARTMENT_HEAD' => [
            'label' => 'Department head',
            'description' => 'Faculty member with read-only oversight of every course in their own department.',
            'assignable' => true,
            'protected' => false,
        ],
        'FACULTY' => [
            'label' => 'Faculty',
            'description' => 'Owns courses and assessments; works with collaborators by invitation.',
            'assignable' => true,
            'protected' => false,
        ],
    ];

    /** @return list<array{module: string, capabilities: list<array{key: string, label: string, roles: array<string, string>, enforced_by: string}>}> */
    public static function modules(): array
    {
        $all = fn (string $admin, string $head, string $faculty) => ['ADMIN' => $admin, 'DEPARTMENT_HEAD' => $head, 'FACULTY' => $faculty];

        return [
            ['module' => 'Admin control center', 'capabilities' => [
                ['key' => 'admin.panel', 'label' => 'Open the admin panel and every /api/admin endpoint', 'roles' => $all('full', 'none', 'none'), 'enforced_by' => 'EnsureAdmin middleware (`admin`)'],
                ['key' => 'admin.users', 'label' => 'Create, edit, suspend and import accounts', 'roles' => $all('full', 'none', 'none'), 'enforced_by' => 'AdminUserController behind `admin`'],
                ['key' => 'admin.roles', 'label' => 'Assign FACULTY / DEPARTMENT_HEAD (ADMIN is never assignable)', 'roles' => $all('full', 'none', 'none'), 'enforced_by' => 'AdminUserController::ASSIGNABLE_ROLES'],
                ['key' => 'admin.horizon', 'label' => 'Open the Horizon queue dashboard', 'roles' => $all('full', 'none', 'none'), 'enforced_by' => 'HorizonServiceProvider gate'],
                ['key' => 'admin.backups', 'label' => 'Create, verify and download database backups', 'roles' => $all('full', 'none', 'none'), 'enforced_by' => 'AdminBackupController behind `admin`'],
            ]],
            ['module' => 'Courses and assessments', 'capabilities' => [
                ['key' => 'course.own', 'label' => 'Create and manage own courses, outcomes and assessments', 'roles' => $all('full', 'full', 'full'), 'enforced_by' => 'CourseAccessService (owner check)'],
                ['key' => 'course.collaborate', 'label' => 'Work on another faculty member\'s course', 'roles' => $all('full', 'invited', 'invited'), 'enforced_by' => 'CourseAccessService (collaborator role)'],
                ['key' => 'course.department', 'label' => 'View every course in own department', 'roles' => $all('full', 'read', 'none'), 'enforced_by' => 'CourseAccessService::headsDepartmentOf'],
                ['key' => 'grades.finalize', 'label' => 'Finalize student grades', 'roles' => $all('owner only', 'owner only', 'owner only'), 'enforced_by' => 'AiGradingService + CourseAccessService (grading ability)'],
            ]],
            ['module' => 'Reporting and analytics', 'capabilities' => [
                ['key' => 'analytics.scope', 'label' => 'Analytics across courses', 'roles' => $all('all courses', 'department', 'own + shared'), 'enforced_by' => 'AnalyticsScopeService'],
                ['key' => 'reports.institutional', 'label' => 'Institutional reports', 'roles' => $all('all', 'department', 'own'), 'enforced_by' => 'ReportAuthorizationService'],
                ['key' => 'department.overview', 'label' => 'Department overview page', 'roles' => $all('any department', 'own department', 'none'), 'enforced_by' => 'PlanningController::department'],
            ]],
            ['module' => 'AI', 'capabilities' => [
                ['key' => 'ai.use', 'label' => 'Run AI analysis, chat, generation and grading assistance', 'roles' => $all('full', 'full', 'full'), 'enforced_by' => 'feature:<key> middleware (admin toggles)'],
                ['key' => 'ai.evaluation.all', 'label' => 'See every AI evaluation dataset and run', 'roles' => $all('full', 'own', 'own'), 'enforced_by' => 'AiEvaluationService::visibleDatasets/visibleRuns'],
                ['key' => 'ai.decide', 'label' => 'AI output becomes an official decision', 'roles' => $all('never', 'never', 'never'), 'enforced_by' => 'Faculty review/approve steps on every AI result'],
            ]],
        ];
    }
}
