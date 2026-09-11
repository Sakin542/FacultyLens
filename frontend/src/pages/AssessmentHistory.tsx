import React, { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Card, CardTitle, CardDescription } from '@/components/common/Card';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import { Input } from '@/components/common/Input';
import { Assessment, Course } from '@/types';
import { assessmentService } from '@/services/assessmentService';
import { courseService } from '@/services/courseService';
import {
  Search,
  Eye,
  Calendar,
  Layers,
  Award,
  Loader2,
  AlertCircle,
  FileCheck2,
  FileText,
} from 'lucide-react';

export const AssessmentHistory: React.FC = () => {
  const navigate = useNavigate();
  const [historyRecords, setHistoryRecords] = useState<Assessment[]>([]);
  const [courses, setCourses] = useState<Course[]>([]);
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedCourseId, setSelectedCourseId] = useState('all');
  const [selectedType, setSelectedType] = useState('all');
  const [selectedStatus, setSelectedStatus] = useState('all');
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const loadHistory = useCallback(async () => {
    try {
      setIsLoading(true);
      setError(null);
      const [histRes, coursesRes] = await Promise.all([
        assessmentService.getHistory({
          course_id: selectedCourseId !== 'all' ? selectedCourseId : undefined,
          type: selectedType !== 'all' ? selectedType : undefined,
          status: selectedStatus !== 'all' ? selectedStatus : undefined,
          search: searchQuery || undefined,
        }),
        courseService.getAll(),
      ]);

      setHistoryRecords(histRes.data || []);
      setCourses(coursesRes.data || []);
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError('Failed to load assessment history.');
      }
    } finally {
      setIsLoading(false);
    }
  }, [selectedCourseId, selectedType, selectedStatus, searchQuery]);

  useEffect(() => {
    loadHistory();
  }, [loadHistory]);

  return (
    <div className="space-y-6">
      {/* Top Bar Controls */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div className="relative flex-1 max-w-md">
          <Input
            placeholder="Search assessment history by title or course..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            leftIcon={<Search className="w-4 h-4" />}
          />
        </div>
      </div>

      {/* Filter Toolbar */}
      <div className="flex flex-wrap items-center gap-3 p-3 bg-sage-100 dark:bg-[#2C2C2E] rounded-xl border border-sage-200 dark:border-[#3A3A3C] text-xs">
        <div className="flex items-center gap-1.5">
          <span className="font-semibold text-sage-500">Course:</span>
          <select
            value={selectedCourseId}
            onChange={(e) => setSelectedCourseId(e.target.value)}
            className="rounded-lg border border-sage-200 dark:border-[#3A3A3C] bg-white dark:bg-[#1C1C1E] px-2.5 py-1 text-xs text-sage-800 dark:text-white"
          >
            <option value="all">All Courses</option>
            {courses.map((c) => (
              <option key={c.id} value={c.id}>
                {c.course_code || c.code} — {c.course_name || c.title}
              </option>
            ))}
          </select>
        </div>

        <div className="flex items-center gap-1.5">
          <span className="font-semibold text-sage-500">Type:</span>
          <select
            value={selectedType}
            onChange={(e) => setSelectedType(e.target.value)}
            className="rounded-lg border border-sage-200 dark:border-[#3A3A3C] bg-white dark:bg-[#1C1C1E] px-2.5 py-1 text-xs text-sage-800 dark:text-white capitalize"
          >
            <option value="all">All Types</option>
            <option value="quiz">Quiz</option>
            <option value="class_test">Class Test</option>
            <option value="midterm">Midterm</option>
            <option value="final">Final</option>
            <option value="assignment">Assignment</option>
            <option value="project">Project</option>
          </select>
        </div>

        <div className="flex items-center gap-1.5">
          <span className="font-semibold text-sage-500">Status:</span>
          <select
            value={selectedStatus}
            onChange={(e) => setSelectedStatus(e.target.value)}
            className="rounded-lg border border-sage-200 dark:border-[#3A3A3C] bg-white dark:bg-[#1C1C1E] px-2.5 py-1 text-xs text-sage-800 dark:text-white capitalize"
          >
            <option value="all">All Statuses</option>
            <option value="draft">Draft</option>
            <option value="published">Published</option>
            <option value="completed">Completed</option>
          </select>
        </div>
      </div>

      {/* Error State */}
      {error && (
        <div className="p-4 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900 rounded-xl flex items-center justify-between text-red-700 dark:text-red-300 text-sm font-medium">
          <div className="flex items-center gap-3">
            <AlertCircle className="w-5 h-5 shrink-0 text-red-500" />
            <span>{error}</span>
          </div>
          <Button variant="outline" size="sm" onClick={() => loadHistory()}>
            Retry
          </Button>
        </div>
      )}

      {/* History Table Card */}
      <Card padding="none" className="overflow-hidden">
        <div className="p-6 border-b border-sage-200 dark:border-[#2C2C2E] flex items-center justify-between">
          <div>
            <CardTitle>Faculty Assessment History</CardTitle>
            <CardDescription>
              Chronological log of created assessments, question papers, and past evaluations
            </CardDescription>
          </div>
          <Badge variant="outline" className="font-mono text-xs">
            {historyRecords.length} Recorded
          </Badge>
        </div>

        {isLoading ? (
          <div className="flex flex-col items-center justify-center min-h-[250px] space-y-3">
            <Loader2 className="w-8 h-8 animate-spin text-sage-800 dark:text-white" />
            <p className="text-xs text-sage-500">Loading history records...</p>
          </div>
        ) : historyRecords.length === 0 ? (
          <div className="p-12 text-center space-y-3">
            <FileCheck2 className="w-10 h-10 text-sage-500 mx-auto opacity-50" />
            <p className="text-sm font-bold text-sage-800 dark:text-white">No assessment history found</p>
            <p className="text-xs text-sage-500 max-w-sm mx-auto">
              Created and completed assessments will appear here chronologically for historical record keeping.
            </p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-sage-100 dark:bg-[#2C2C2E] border-b border-sage-200 dark:border-[#3A3A3C] text-sage-500 uppercase tracking-wider font-semibold">
                <tr>
                  <th className="px-6 py-3.5">Assessment & Course</th>
                  <th className="px-6 py-3.5">Type</th>
                  <th className="px-6 py-3.5">Date</th>
                  <th className="px-6 py-3.5">Marks & Duration</th>
                  <th className="px-6 py-3.5">Question Paper</th>
                  <th className="px-6 py-3.5">Status</th>
                  <th className="px-6 py-3.5 text-right">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-sage-200 dark:divide-[#2C2C2E]">
                {historyRecords.map((asm) => {
                  const paper = asm.questionPaper || asm.question_paper;
                  const qCount = asm.questions_count ?? (asm.questions ? asm.questions.length : 0);

                  return (
                    <tr key={asm.id} className="hover:bg-sage-100 dark:hover:bg-[#2C2C2E]/60 transition-colors">
                      <td className="px-6 py-4">
                        <div className="font-semibold text-sage-800 dark:text-white text-sm">
                          {asm.title}
                        </div>
                        <div className="text-[11px] text-sage-500 mt-0.5">
                          <span className="font-mono font-medium text-sage-700 dark:text-sage-200">
                            {asm.course?.course_code || asm.courseCode}
                          </span>{' '}
                          • {asm.course?.course_name || asm.courseTitle}
                        </div>
                      </td>

                      <td className="px-6 py-4">
                        <Badge variant="neutral" className="text-[11px] capitalize">
                          {asm.type}
                        </Badge>
                      </td>

                      <td className="px-6 py-4 font-mono text-[11px] text-sage-500">
                        <span className="flex items-center gap-1">
                          <Calendar className="w-3 h-3" />
                          {asm.assessment_date ? asm.assessment_date.split('T')[0] : 'TBD'}
                        </span>
                      </td>

                      <td className="px-6 py-4">
                        <div className="font-mono font-bold text-sage-800 dark:text-white flex items-center gap-1">
                          <Award className="w-3 h-3 text-sage-500" />
                          {asm.total_marks || asm.totalMarks} Marks
                        </div>
                        <div className="text-[10px] text-sage-500 flex items-center gap-1 mt-0.5">
                          <Layers className="w-3 h-3" />
                          {qCount} Questions • {asm.duration_minutes || 90}m
                        </div>
                      </td>

                      <td className="px-6 py-4">
                        {paper ? (
                          <div className="flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400 font-medium">
                            <FileText className="w-3.5 h-3.5 shrink-0" />
                            <span className="truncate max-w-[140px]">{paper.file_name}</span>
                          </div>
                        ) : (
                          <span className="text-[11px] text-sage-500">No file</span>
                        )}
                      </td>

                      <td className="px-6 py-4">
                        <Badge
                          variant={asm.status === 'completed' || asm.status === 'Analyzed' ? 'default' : 'neutral'}
                          className="capitalize"
                        >
                          {asm.status}
                        </Badge>
                      </td>

                      <td className="px-6 py-4 text-right">
                        <Button
                          variant="outline"
                          size="sm"
                          leftIcon={<Eye className="w-3.5 h-3.5" />}
                          onClick={() => navigate(`/assessments/${asm.id}`)}
                        >
                          View
                        </Button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
};

export const History = AssessmentHistory;

