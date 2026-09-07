import React, { useState } from 'react';
import { Card } from '@/components/common/Card';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import { Input } from '@/components/common/Input';
import { mockCourses } from '@/utils/mockData';
import { Course } from '@/types';
import {
  BookOpen,
  Plus,
  Search,
  Users,
  FileCheck2,
  ListChecks,
  ExternalLink,
  Edit,
  X,
  Sparkles,
} from 'lucide-react';

export const Courses: React.FC = () => {
  const [courses, setCourses] = useState<Course[]>(mockCourses);
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedCourse, setSelectedCourse] = useState<Course | null>(null);
  const [isAddModalOpen, setIsAddModalOpen] = useState(false);
  const [newCourse, setNewCourse] = useState({
    code: '',
    title: '',
    semester: 'Spring 2026',
    creditHours: '3.0',
    section: 'A',
    department: 'Computer Science & Engineering',
  });

  const filteredCourses = courses.filter(
    (c) =>
      c.title.toLowerCase().includes(searchQuery.toLowerCase()) ||
      c.code.toLowerCase().includes(searchQuery.toLowerCase())
  );

  const handleAddCourse = (e: React.FormEvent) => {
    e.preventDefault();
    if (!newCourse.code || !newCourse.title) return;

    const created: Course = {
      id: `course-${Date.now()}`,
      code: newCourse.code.toUpperCase(),
      title: newCourse.title,
      semester: newCourse.semester,
      year: 2026,
      section: newCourse.section,
      creditHours: parseFloat(newCourse.creditHours) || 3.0,
      department: newCourse.department,
      studentsCount: 45,
      assessmentCount: 0,
      learningOutcomesCount: 3,
      learningOutcomes: [
        { id: `clo-${Date.now()}-1`, code: 'CLO-1', description: 'Fundamental principles and foundational methodologies.', bloomLevel: 'Understand' },
        { id: `clo-${Date.now()}-2`, code: 'CLO-2', description: 'Practical implementation and algorithmic solution design.', bloomLevel: 'Apply' },
        { id: `clo-${Date.now()}-3`, code: 'CLO-3', description: 'System evaluation and architectural optimization.', bloomLevel: 'Evaluate' },
      ],
      createdAt: new Date().toISOString().split('T')[0],
      updatedAt: new Date().toISOString().split('T')[0],
    };

    setCourses([created, ...courses]);
    setIsAddModalOpen(false);
    setNewCourse({
      code: '',
      title: '',
      semester: 'Spring 2026',
      creditHours: '3.0',
      section: 'A',
      department: 'Computer Science & Engineering',
    });
  };

  return (
    <div className="space-y-6">
      {/* Header Controls */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div className="relative flex-1 max-w-md">
          <Input
            placeholder="Search courses by code or title..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            leftIcon={<Search className="w-4 h-4" />}
          />
        </div>
        <div className="flex items-center gap-3">
          <Button
            variant="primary"
            leftIcon={<Plus className="w-4 h-4" />}
            onClick={() => setIsAddModalOpen(true)}
          >
            Add Course
          </Button>
        </div>
      </div>

      {/* Courses Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        {filteredCourses.map((course) => (
          <Card
            key={course.id}
            variant="default"
            className="flex flex-col justify-between hover:border-[#111111] hover:shadow-card transition-all duration-200"
          >
            <div className="space-y-4">
              <div className="flex items-start justify-between">
                <div>
                  <Badge variant="outline" className="font-mono font-bold text-xs bg-[#F7F7F5]">
                    {course.code}
                  </Badge>
                  <h3 className="text-lg font-bold text-[#111111] mt-2 leading-snug">
                    {course.title}
                  </h3>
                </div>
                <div className="w-9 h-9 rounded-lg bg-[#F7F7F5] border border-[#E5E5E5] flex items-center justify-center text-[#111111]">
                  <BookOpen className="w-4 h-4" />
                </div>
              </div>

              <div className="text-xs text-[#737373] space-y-1">
                <p>{course.department}</p>
                <p className="font-mono">{course.semester} • Section {course.section || 'All'}</p>
              </div>

              {/* Stats pill list */}
              <div className="grid grid-cols-3 gap-2 py-3 border-y border-[#E5E5E5] text-center">
                <div>
                  <span className="text-[10px] uppercase font-semibold text-[#737373] block">Students</span>
                  <span className="text-xs font-bold text-[#111111] flex items-center justify-center gap-1 mt-0.5">
                    <Users className="w-3 h-3 text-[#737373]" /> {course.studentsCount}
                  </span>
                </div>
                <div>
                  <span className="text-[10px] uppercase font-semibold text-[#737373] block">Assessments</span>
                  <span className="text-xs font-bold text-[#111111] flex items-center justify-center gap-1 mt-0.5">
                    <FileCheck2 className="w-3 h-3 text-[#737373]" /> {course.assessmentCount}
                  </span>
                </div>
                <div>
                  <span className="text-[10px] uppercase font-semibold text-[#737373] block">Outcomes</span>
                  <span className="text-xs font-bold text-[#111111] flex items-center justify-center gap-1 mt-0.5">
                    <ListChecks className="w-3 h-3 text-[#737373]" /> {course.learningOutcomesCount} CLOs
                  </span>
                </div>
              </div>
            </div>

            <div className="flex items-center gap-2 pt-4 mt-2">
              <Button
                variant="outline"
                size="sm"
                className="flex-1 justify-center"
                leftIcon={<ExternalLink className="w-3.5 h-3.5" />}
                onClick={() => setSelectedCourse(course)}
              >
                View Details
              </Button>
              <Button
                variant="ghost"
                size="sm"
                leftIcon={<Edit className="w-3.5 h-3.5" />}
                onClick={() => setSelectedCourse(course)}
              >
                Edit
              </Button>
            </div>
          </Card>
        ))}
      </div>

      {/* Course Detail Modal */}
      {selectedCourse && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
          <div className="bg-white rounded-2xl border border-[#E5E5E5] shadow-elevated max-w-2xl w-full max-h-[90vh] overflow-y-auto p-6 space-y-6">
            <div className="flex items-start justify-between pb-4 border-b border-[#E5E5E5]">
              <div>
                <Badge variant="outline" className="font-mono">{selectedCourse.code}</Badge>
                <h3 className="text-xl font-bold text-[#111111] mt-1">{selectedCourse.title}</h3>
                <p className="text-xs text-[#737373]">{selectedCourse.semester} • {selectedCourse.department}</p>
              </div>
              <button
                type="button"
                onClick={() => setSelectedCourse(null)}
                className="p-1 rounded-lg text-[#737373] hover:text-[#111111] hover:bg-[#F7F7F5]"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Course Learning Outcomes */}
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <h4 className="text-sm font-bold text-[#111111] flex items-center gap-2">
                  <Sparkles className="w-4 h-4 text-[#111111]" /> Course Learning Outcomes (CLOs)
                </h4>
                <span className="text-xs text-[#737373] font-mono">{selectedCourse.learningOutcomes.length} Defined</span>
              </div>

              <div className="space-y-2">
                {selectedCourse.learningOutcomes.map((clo) => (
                  <div key={clo.id} className="p-3 rounded-lg border border-[#E5E5E5] bg-[#F7F7F5] text-xs space-y-1">
                    <div className="flex items-center justify-between">
                      <span className="font-bold font-mono text-[#111111]">{clo.code}</span>
                      <Badge variant="neutral" className="text-[10px]">Bloom: {clo.bloomLevel}</Badge>
                    </div>
                    <p className="text-[#262626]">{clo.description}</p>
                  </div>
                ))}
              </div>
            </div>

            <div className="flex items-center justify-end gap-3 pt-4 border-t border-[#E5E5E5]">
              <Button variant="outline" size="sm" onClick={() => setSelectedCourse(null)}>
                Close
              </Button>
              <Button variant="primary" size="sm" onClick={() => setSelectedCourse(null)}>
                Save Changes
              </Button>
            </div>
          </div>
        </div>
      )}

      {/* Add Course Modal */}
      {isAddModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
          <div className="bg-white rounded-2xl border border-[#E5E5E5] shadow-elevated max-w-lg w-full p-6 space-y-5">
            <div className="flex items-center justify-between pb-3 border-b border-[#E5E5E5]">
              <h3 className="text-lg font-bold text-[#111111]">Add New Course</h3>
              <button
                type="button"
                onClick={() => setIsAddModalOpen(false)}
                className="p-1 rounded-lg text-[#737373] hover:text-[#111111] hover:bg-[#F7F7F5]"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleAddCourse} className="space-y-4">
              <div className="grid grid-cols-2 gap-3">
                <Input
                  label="Course Code"
                  placeholder="e.g. CSE 4201"
                  value={newCourse.code}
                  onChange={(e) => setNewCourse({ ...newCourse, code: e.target.value })}
                  required
                />
                <Input
                  label="Section"
                  placeholder="e.g. A"
                  value={newCourse.section}
                  onChange={(e) => setNewCourse({ ...newCourse, section: e.target.value })}
                />
              </div>

              <Input
                label="Course Title"
                placeholder="e.g. Artificial Intelligence"
                value={newCourse.title}
                onChange={(e) => setNewCourse({ ...newCourse, title: e.target.value })}
                required
              />

              <div className="grid grid-cols-2 gap-3">
                <Input
                  label="Semester"
                  placeholder="Spring 2026"
                  value={newCourse.semester}
                  onChange={(e) => setNewCourse({ ...newCourse, semester: e.target.value })}
                />
                <Input
                  label="Credit Hours"
                  placeholder="3.0"
                  value={newCourse.creditHours}
                  onChange={(e) => setNewCourse({ ...newCourse, creditHours: e.target.value })}
                />
              </div>

              <Input
                label="Department"
                placeholder="Computer Science & Engineering"
                value={newCourse.department}
                onChange={(e) => setNewCourse({ ...newCourse, department: e.target.value })}
              />

              <div className="flex items-center justify-end gap-3 pt-4 border-t border-[#E5E5E5]">
                <Button variant="outline" size="sm" type="button" onClick={() => setIsAddModalOpen(false)}>
                  Cancel
                </Button>
                <Button variant="primary" size="sm" type="submit">
                  Create Course
                </Button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

