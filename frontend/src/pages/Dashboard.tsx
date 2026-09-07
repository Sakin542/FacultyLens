import React from 'react';
import { useNavigate } from 'react-router-dom';
import { StatCard } from '@/components/dashboard/StatCard';
import { ProgressBar } from '@/components/dashboard/ProgressBar';
import { Card, CardHeader, CardTitle, CardContent, CardDescription } from '@/components/common/Card';
import { Badge } from '@/components/common/Badge';
import { Button } from '@/components/common/Button';
import { useAuth } from '@/context/AuthContext';
import {
  mockDashboardStats,
  mockAssessments,
} from '@/utils/mockData';
import {
  BookOpen,
  FileCheck2,
  BrainCircuit,
  Lightbulb,
  ArrowRight,
  Sparkles,
  Plus,
  Eye,
  AlertTriangle,
} from 'lucide-react';

export const Dashboard: React.FC = () => {
  const navigate = useNavigate();
  const { user } = useAuth();
  const displayName = user?.name || user?.fullName || 'Faculty Member';
  const displayDept = user?.department || 'Academic Department';

  return (
    <div className="space-y-8">
      {/* Welcome Banner */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-6 bg-white rounded-xl border border-[#E5E5E5] shadow-subtle">
        <div className="space-y-1">
          <div className="flex items-center gap-2">
            <h2 className="text-xl font-bold text-[#111111] tracking-tight">
              Welcome back, {displayName}
            </h2>
            <Badge variant="neutral" className="font-mono text-[10px]">
              {displayDept}
            </Badge>
          </div>
          <p className="text-xs text-[#737373]">
            You have <strong className="text-[#111111]">2 assessments</strong> ready for review and <strong className="text-[#111111]">3 high-priority recommendations</strong> for Spring 2026.
          </p>
        </div>

        <div className="flex items-center gap-2.5">
          <Button
            variant="outline"
            size="sm"
            leftIcon={<Plus className="w-4 h-4" />}
            onClick={() => navigate('/assessments')}
          >
            New Assessment
          </Button>
          <Button
            variant="primary"
            size="sm"
            leftIcon={<Sparkles className="w-4 h-4" />}
            onClick={() => navigate('/analysis')}
          >
            Latest Analysis
          </Button>
        </div>
      </div>

      {/* KPI Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard
          label="Total Courses"
          value={mockDashboardStats.totalCourses}
          description="Active in Spring 2026"
          icon={<BookOpen className="w-5 h-5" />}
        />
        <StatCard
          label="Total Assessments"
          value={mockDashboardStats.totalAssessments}
          description="Across all courses"
          icon={<FileCheck2 className="w-5 h-5" />}
        />
        <StatCard
          label="Analyses Completed"
          value={mockDashboardStats.analysesCompleted}
          description="AI quality audits performed"
          icon={<BrainCircuit className="w-5 h-5" />}
        />
        <StatCard
          label="Recommendations"
          value={mockDashboardStats.recommendationsCount}
          description="Actionable insights surfaced"
          icon={<Lightbulb className="w-5 h-5" />}
        />
      </div>

      {/* Analytics Visualization Grid */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
        {/* Quality & Alignment Breakdown */}
        <div className="lg:col-span-7 space-y-6">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0">
              <div>
                <CardTitle>Academic Quality Indicators</CardTitle>
                <CardDescription>Aggregate metrics across analyzed assessments</CardDescription>
              </div>
              <Badge variant="Analyzed" dot>Healthy</Badge>
            </CardHeader>
            <CardContent className="space-y-5 pt-2">
              <ProgressBar
                label="Assessment Quality"
                sublabel="Rigor, clarity, & structural validity"
                value={86}
                size="md"
              />
              <ProgressBar
                label="Topic Coverage"
                sublabel="Syllabus module representation"
                value={89}
                size="md"
              />
              <ProgressBar
                label="Learning Outcome Alignment"
                sublabel="Bloom taxonomy & CLO mapping"
                value={84}
                size="md"
              />

              <div className="pt-4 border-t border-[#E5E5E5] grid grid-cols-3 gap-3 text-center">
                <div className="p-3 bg-[#F7F7F5] rounded-lg border border-[#E5E5E5]">
                  <span className="text-[11px] text-[#737373] block">Avg Quality</span>
                  <span className="text-lg font-bold text-[#111111]">86%</span>
                </div>
                <div className="p-3 bg-[#F7F7F5] rounded-lg border border-[#E5E5E5]">
                  <span className="text-[11px] text-[#737373] block">CLO Coverage</span>
                  <span className="text-lg font-bold text-[#111111]">84%</span>
                </div>
                <div className="p-3 bg-[#F7F7F5] rounded-lg border border-[#E5E5E5]">
                  <span className="text-[11px] text-[#737373] block">Duplicates Flagged</span>
                  <span className="text-lg font-bold text-[#DC2626]">2</span>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Difficulty Distribution */}
        <div className="lg:col-span-5 space-y-6">
          <Card className="flex flex-col justify-between">
            <div>
              <CardHeader>
                <CardTitle>Difficulty Distribution</CardTitle>
                <CardDescription>Cognitive level balance across questions</CardDescription>
              </CardHeader>
              <CardContent className="space-y-4 pt-2">
                {/* CSS Multi-segmented bar */}
                <div className="space-y-2">
                  <div className="flex justify-between text-xs text-[#737373]">
                    <span>Easy (20%)</span>
                    <span>Medium (60%)</span>
                    <span>Hard (20%)</span>
                  </div>
                  <div className="h-4 w-full bg-[#E5E5E5] rounded-full overflow-hidden flex shadow-inner">
                    <div className="bg-[#16A34A] h-full transition-all" style={{ width: '20%' }} title="Easy: 20%" />
                    <div className="bg-[#111111] h-full transition-all" style={{ width: '60%' }} title="Medium: 60%" />
                    <div className="bg-[#DC2626] h-full transition-all" style={{ width: '20%' }} title="Hard: 20%" />
                  </div>
                </div>

                <div className="space-y-2.5 pt-4">
                  <div className="flex items-center justify-between p-2.5 rounded-lg bg-[#F7F7F5] border border-[#E5E5E5] text-xs">
                    <div className="flex items-center gap-2">
                      <span className="w-2.5 h-2.5 rounded-full bg-[#16A34A]"></span>
                      <span className="font-medium text-[#111111]">Easy / Lower Order (Remember, Understand)</span>
                    </div>
                    <span className="font-bold font-mono text-[#111111]">20%</span>
                  </div>

                  <div className="flex items-center justify-between p-2.5 rounded-lg bg-[#F7F7F5] border border-[#E5E5E5] text-xs">
                    <div className="flex items-center gap-2">
                      <span className="w-2.5 h-2.5 rounded-full bg-[#111111]"></span>
                      <span className="font-medium text-[#111111]">Medium / Application (Apply, Analyze)</span>
                    </div>
                    <span className="font-bold font-mono text-[#111111]">60%</span>
                  </div>

                  <div className="flex items-center justify-between p-2.5 rounded-lg bg-[#F7F7F5] border border-[#E5E5E5] text-xs">
                    <div className="flex items-center gap-2">
                      <span className="w-2.5 h-2.5 rounded-full bg-[#DC2626]"></span>
                      <span className="font-medium text-[#111111]">Hard / Higher Order (Evaluate, Create)</span>
                    </div>
                    <span className="font-bold font-mono text-[#111111]">20%</span>
                  </div>
                </div>
              </CardContent>
            </div>

            <div className="p-3 bg-[#FFFBEB] rounded-lg border border-[#FDE68A] flex items-start gap-2.5 text-xs text-[#92400E] mt-4">
              <AlertTriangle className="w-4 h-4 shrink-0 text-[#D97706] mt-0.5" />
              <span>Recommended distribution target: 20% Easy, 50-60% Medium, 20-30% Hard. Current assessment design is well balanced.</span>
            </div>
          </Card>
        </div>
      </div>

      {/* Recent Assessments Table */}
      <Card>
        <CardHeader className="flex flex-row items-center justify-between space-y-0">
          <div>
            <CardTitle>Recent Assessments</CardTitle>
            <CardDescription>Evaluation papers uploaded during this semester</CardDescription>
          </div>
          <Button
            variant="ghost"
            size="sm"
            rightIcon={<ArrowRight className="w-3.5 h-3.5" />}
            onClick={() => navigate('/assessments')}
          >
            View All
          </Button>
        </CardHeader>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-[#F7F7F5] border-b border-[#E5E5E5] text-[#737373] uppercase tracking-wider font-semibold">
                <tr>
                  <th className="px-6 py-3">Assessment</th>
                  <th className="px-6 py-3">Course</th>
                  <th className="px-6 py-3">Status</th>
                  <th className="px-6 py-3">Quality Score</th>
                  <th className="px-6 py-3 text-right">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-[#E5E5E5]">
                {mockAssessments.slice(0, 4).map((asm) => (
                  <tr key={asm.id} className="hover:bg-[#F7F7F5] transition-colors">
                    <td className="px-6 py-4 font-semibold text-[#111111]">
                      {asm.title}
                      <span className="block text-[11px] text-[#737373] font-normal">
                        {asm.totalQuestions} Questions • {asm.totalMarks} Marks
                      </span>
                    </td>
                    <td className="px-6 py-4">
                      <span className="font-mono font-medium text-[#111111]">{asm.courseCode}</span>
                      <span className="block text-[11px] text-[#737373]">{asm.courseTitle}</span>
                    </td>
                    <td className="px-6 py-4">
                      <Badge variant={asm.status as any} dot>
                        {asm.status}
                      </Badge>
                    </td>
                    <td className="px-6 py-4 font-mono font-semibold text-[#111111]">
                      {asm.qualityScore ? `${asm.qualityScore}%` : '—'}
                    </td>
                    <td className="px-6 py-4 text-right">
                      {asm.status === 'Analyzed' ? (
                        <Button
                          variant="outline"
                          size="sm"
                          leftIcon={<Eye className="w-3.5 h-3.5" />}
                          onClick={() => navigate('/analysis')}
                        >
                          View Analysis
                        </Button>
                      ) : (
                        <Button
                          variant="primary"
                          size="sm"
                          leftIcon={<Sparkles className="w-3.5 h-3.5" />}
                          onClick={() => navigate('/analysis')}
                        >
                          Analyze
                        </Button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

