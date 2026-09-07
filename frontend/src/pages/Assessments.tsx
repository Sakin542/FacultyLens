import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Card } from '@/components/common/Card';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import { Input } from '@/components/common/Input';
import { mockAssessments } from '@/utils/mockData';
import { Assessment } from '@/types';
import {
  Plus,
  Search,
  Eye,
  Sparkles,
  UploadCloud,
  X,
} from 'lucide-react';

export const Assessments: React.FC = () => {
  const navigate = useNavigate();
  const [assessments, setAssessments] = useState<Assessment[]>(mockAssessments);
  const [searchQuery, setSearchQuery] = useState('');
  const [activeFilter, setActiveFilter] = useState<string>('all');
  const [isNewModalOpen, setIsNewModalOpen] = useState(false);
  const [isAnalyzingId, setIsAnalyzingId] = useState<string | null>(null);

  const [newAsm, setNewAsm] = useState({
    title: '',
    courseCode: 'CSE 3201',
    courseTitle: 'Database Systems',
    type: 'Midterm',
    totalQuestions: '8',
    totalMarks: '50',
  });

  const filteredAssessments = assessments.filter((asm) => {
    const matchesSearch =
      asm.title.toLowerCase().includes(searchQuery.toLowerCase()) ||
      asm.courseCode.toLowerCase().includes(searchQuery.toLowerCase()) ||
      asm.courseTitle.toLowerCase().includes(searchQuery.toLowerCase());

    if (activeFilter === 'all') return matchesSearch;
    return matchesSearch && asm.status.toLowerCase() === activeFilter.toLowerCase();
  });

  const handleCreateAssessment = (e: React.FormEvent) => {
    e.preventDefault();
    if (!newAsm.title) return;

    const created: Assessment = {
      id: `asm-${Date.now()}`,
      courseId: 'course-1',
      courseCode: newAsm.courseCode,
      courseTitle: newAsm.courseTitle,
      title: newAsm.title,
      type: newAsm.type as any,
      semester: 'Spring 2026',
      status: 'Pending',
      totalQuestions: parseInt(newAsm.totalQuestions) || 8,
      totalMarks: parseInt(newAsm.totalMarks) || 50,
      uploadedAt: new Date().toISOString().split('T')[0],
    };

    setAssessments([created, ...assessments]);
    setIsNewModalOpen(false);
  };

  const handleTriggerAnalysis = (asmId: string) => {
    setIsAnalyzingId(asmId);
    setTimeout(() => {
      setIsAnalyzingId(null);
      navigate('/analysis');
    }, 800);
  };

  return (
    <div className="space-y-6">
      {/* Top Header & Search Controls */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div className="relative flex-1 max-w-md">
          <Input
            placeholder="Search assessments by title or course..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            leftIcon={<Search className="w-4 h-4" />}
          />
        </div>

        <div className="flex items-center gap-3">
          <Button
            variant="primary"
            leftIcon={<Plus className="w-4 h-4" />}
            onClick={() => setIsNewModalOpen(true)}
          >
            New Assessment
          </Button>
        </div>
      </div>

      {/* Filter Tabs */}
      <div className="flex items-center gap-2 border-b border-[#E5E5E5] pb-2 text-xs">
        {['all', 'Analyzed', 'Pending', 'Needs Review'].map((filter) => (
          <button
            key={filter}
            type="button"
            onClick={() => setActiveFilter(filter)}
            className={`px-3 py-1.5 rounded-lg font-medium transition-colors ${
              activeFilter === filter
                ? 'bg-[#111111] text-white'
                : 'text-[#737373] hover:text-[#111111] hover:bg-[#E5E5E5]'
            }`}
          >
            {filter.charAt(0).toUpperCase() + filter.slice(1)}
          </button>
        ))}
      </div>

      {/* Assessments Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        {filteredAssessments.map((asm) => (
          <Card
            key={asm.id}
            variant="default"
            className="flex flex-col justify-between hover:border-[#111111] hover:shadow-card transition-all duration-200"
          >
            <div className="space-y-4">
              <div className="flex items-start justify-between">
                <div>
                  <div className="flex items-center gap-2">
                    <Badge variant="outline" className="font-mono text-xs">{asm.courseCode}</Badge>
                    <Badge variant={asm.status as any} dot>{asm.status}</Badge>
                  </div>
                  <h3 className="text-base font-bold text-[#111111] mt-2.5 leading-snug">
                    {asm.title}
                  </h3>
                  <p className="text-xs text-[#737373]">{asm.courseTitle} • {asm.semester}</p>
                </div>
              </div>

              {/* Assessment Metric Pills */}
              <div className="p-3 bg-[#F7F7F5] rounded-lg border border-[#E5E5E5] space-y-2">
                <div className="flex items-center justify-between text-xs">
                  <span className="text-[#737373]">Quality Score</span>
                  <span className="font-bold text-[#111111] font-mono">
                    {asm.qualityScore ? `${asm.qualityScore}%` : 'Pending Analysis'}
                  </span>
                </div>
                {asm.qualityScore && (
                  <div className="w-full bg-[#E5E5E5] h-1.5 rounded-full overflow-hidden">
                    <div
                      className="bg-[#16A34A] h-full rounded-full"
                      style={{ width: `${asm.qualityScore}%` }}
                    />
                  </div>
                )}
                <div className="flex items-center justify-between text-[11px] text-[#737373] pt-1">
                  <span>{asm.totalQuestions} Questions</span>
                  <span>{asm.totalMarks} Total Marks</span>
                </div>
              </div>
            </div>

            {/* Actions */}
            <div className="pt-4 border-t border-[#E5E5E5] mt-2 flex items-center gap-2">
              {asm.status === 'Analyzed' ? (
                <Button
                  variant="primary"
                  size="sm"
                  className="w-full justify-center"
                  leftIcon={<Eye className="w-3.5 h-3.5" />}
                  onClick={() => navigate('/analysis')}
                >
                  View Analysis
                </Button>
              ) : (
                <Button
                  variant="primary"
                  size="sm"
                  className="w-full justify-center"
                  isLoading={isAnalyzingId === asm.id}
                  leftIcon={<Sparkles className="w-3.5 h-3.5" />}
                  onClick={() => handleTriggerAnalysis(asm.id)}
                >
                  Analyze Paper
                </Button>
              )}
            </div>
          </Card>
        ))}
      </div>

      {/* New Assessment Upload Modal */}
      {isNewModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
          <div className="bg-white rounded-2xl border border-[#E5E5E5] shadow-elevated max-w-lg w-full p-6 space-y-5">
            <div className="flex items-center justify-between pb-3 border-b border-[#E5E5E5]">
              <h3 className="text-lg font-bold text-[#111111]">Upload New Assessment Paper</h3>
              <button
                type="button"
                onClick={() => setIsNewModalOpen(false)}
                className="p-1 rounded-lg text-[#737373] hover:text-[#111111] hover:bg-[#F7F7F5]"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleCreateAssessment} className="space-y-4">
              <Input
                label="Assessment Title"
                placeholder="e.g. CSE 3201 Midterm Examination"
                value={newAsm.title}
                onChange={(e) => setNewAsm({ ...newAsm, title: e.target.value })}
                required
              />

              <div className="grid grid-cols-2 gap-3">
                <Input
                  label="Course Code"
                  value={newAsm.courseCode}
                  onChange={(e) => setNewAsm({ ...newAsm, courseCode: e.target.value })}
                  required
                />
                <Input
                  label="Course Title"
                  value={newAsm.courseTitle}
                  onChange={(e) => setNewAsm({ ...newAsm, courseTitle: e.target.value })}
                  required
                />
              </div>

              <div className="grid grid-cols-3 gap-3">
                <Input
                  label="Type"
                  value={newAsm.type}
                  onChange={(e) => setNewAsm({ ...newAsm, type: e.target.value })}
                />
                <Input
                  label="Questions"
                  type="number"
                  value={newAsm.totalQuestions}
                  onChange={(e) => setNewAsm({ ...newAsm, totalQuestions: e.target.value })}
                />
                <Input
                  label="Total Marks"
                  type="number"
                  value={newAsm.totalMarks}
                  onChange={(e) => setNewAsm({ ...newAsm, totalMarks: e.target.value })}
                />
              </div>

              {/* Upload Dropzone UI Simulation */}
              <div className="border border-dashed border-[#CCCCCC] rounded-xl p-6 text-center space-y-2 bg-[#F7F7F5]">
                <div className="w-10 h-10 rounded-full bg-white border border-[#E5E5E5] flex items-center justify-center mx-auto text-[#111111]">
                  <UploadCloud className="w-5 h-5" />
                </div>
                <p className="text-xs font-semibold text-[#111111]">
                  Click to browse or drop PDF / DOCX file here
                </p>
                <p className="text-[11px] text-[#737373]">
                  Supports Question Papers, Syllabi & Mark Schemes (Max 25MB)
                </p>
              </div>

              <div className="flex items-center justify-end gap-3 pt-4 border-t border-[#E5E5E5]">
                <Button variant="outline" size="sm" type="button" onClick={() => setIsNewModalOpen(false)}>
                  Cancel
                </Button>
                <Button variant="primary" size="sm" type="submit">
                  Upload & Queue
                </Button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

