import React, { useState, useEffect, useCallback } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { Course, LearningOutcome, CourseMaterial, DocumentProcessing } from '@/types';
import { courseService, CoursePayload } from '@/services/courseService';
import { learningOutcomeService, LearningOutcomePayload } from '@/services/learningOutcomeService';
import { courseMaterialService } from '@/services/courseMaterialService';
import { documentService } from '@/services/documentService';
import { Card } from '@/components/common/Card';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import { CourseModal } from '@/components/courses/CourseModal';
import { LearningOutcomeModal } from '@/components/courses/LearningOutcomeModal';
import { MaterialUploadModal } from '@/components/courses/MaterialUploadModal';
import { DocumentUploadModal } from '@/components/documents/DocumentUploadModal';
import { DocumentList } from '@/components/documents/DocumentList';
import {
  ArrowLeft,
  Edit,
  Trash2,
  Plus,
  Sparkles,
  UploadCloud,
  FileText,
  Download,
  Calendar,
  Layers,
  FileCheck2,
  AlertCircle,
  Loader2,
  CheckCircle2,
  Grid3X3,
  MessageSquareText,
} from 'lucide-react';

export const CourseDetails: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();

  const [course, setCourse] = useState<Course | null>(null);
  const [learningOutcomes, setLearningOutcomes] = useState<LearningOutcome[]>([]);
  const [materials, setMaterials] = useState<CourseMaterial[]>([]);
  const [documents, setDocuments] = useState<DocumentProcessing[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isLoadingDocs, setIsLoadingDocs] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  // Modal states
  const [isEditCourseOpen, setIsEditCourseOpen] = useState(false);
  const [isAddCloOpen, setIsAddCloOpen] = useState(false);
  const [editingClo, setEditingClo] = useState<LearningOutcome | null>(null);
  const [isUploadMaterialOpen, setIsUploadMaterialOpen] = useState(false);
  const [isUploadDocOpen, setIsUploadDocOpen] = useState(false);

  // Deletion confirm states
  const [deletingCourse, setDeletingCourse] = useState(false);
  const [deletingCloId, setDeletingCloId] = useState<number | string | null>(null);
  const [deletingMaterialId, setDeletingMaterialId] = useState<number | string | null>(null);

  const showNotification = (msg: string) => {
    setSuccessMessage(msg);
    setTimeout(() => setSuccessMessage(null), 4000);
  };

  const fetchCourseDetails = useCallback(async () => {
    if (!id) return;
    try {
      setIsLoading(true);
      setError(null);
      const res = await courseService.getById(id);
      setCourse(res.data);
      setLearningOutcomes(res.data.learning_outcomes || res.data.learningOutcomes || []);
      setMaterials(res.data.materials || []);

      // Fetch documents for this course
      try {
        setIsLoadingDocs(true);
        const docsRes = await documentService.getAll({ course_id: id });
        setDocuments(docsRes.data || []);
      } catch (docErr) {
        console.warn('Could not load documents:', docErr);
      } finally {
        setIsLoadingDocs(false);
      }
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError('Failed to load course details.');
      }
    } finally {
      setIsLoading(false);
    }
  }, [id]);

  useEffect(() => {
    fetchCourseDetails();
  }, [fetchCourseDetails]);

  // Handle Document Upload
  const handleUploadDocument = async (formData: FormData) => {
    await documentService.upload(formData);
    // Reload docs
    if (id) {
      const docsRes = await documentService.getAll({ course_id: id });
      setDocuments(docsRes.data || []);
    }
    showNotification('Document uploaded and parsed successfully!');
  };

  // Handle Document Download
  const handleDownloadDocument = async (doc: DocumentProcessing) => {
    try {
      await documentService.download(doc.id, doc.original_file_name);
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      }
    }
  };

  // Handle Document Delete
  const handleDeleteDocument = async (docId: number | string) => {
    if (!window.confirm('Are you sure you want to delete this document?')) return;
    try {
      await documentService.delete(docId);
      setDocuments(documents.filter((d) => d.id !== docId));
      showNotification('Document removed successfully!');
    } catch (err: unknown) {
      if (err instanceof Error) setError(err.message);
    }
  };

  // Handle Document Reprocess
  const handleReprocessDocument = async (docId: number | string) => {
    try {
      const res = await documentService.reprocess(docId);
      setDocuments(documents.map((d) => (d.id === docId ? res.data : d)));
      showNotification('Document reprocessed successfully!');
    } catch (err: unknown) {
      if (err instanceof Error) setError(err.message);
    }
  };

  // Handle Course Update
  const handleUpdateCourse = async (data: CoursePayload) => {
    if (!id) return;
    const res = await courseService.update(id, data);
    setCourse(res.data);
    showNotification('Course updated successfully!');
  };

  // Handle Course Delete
  const handleDeleteCourse = async () => {
    if (!id || !course) return;
    if (!window.confirm(`Are you sure you want to delete "${course.course_code || course.code} - ${course.course_name || course.title}"? This cannot be undone.`)) {
      return;
    }

    try {
      setDeletingCourse(true);
      await courseService.delete(id);
      navigate('/courses');
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      }
    } finally {
      setDeletingCourse(false);
    }
  };

  // Handle Add CLO
  const handleAddClo = async (data: LearningOutcomePayload) => {
    if (!id) return;
    const res = await learningOutcomeService.create(id, data);
    setLearningOutcomes([...learningOutcomes, res.data]);
    showNotification('Learning outcome added successfully!');
  };

  // Handle Edit CLO
  const handleUpdateClo = async (data: LearningOutcomePayload) => {
    if (!editingClo) return;
    const res = await learningOutcomeService.update(editingClo.id, data);
    setLearningOutcomes(learningOutcomes.map((clo) => (clo.id === editingClo.id ? res.data : clo)));
    setEditingClo(null);
    showNotification('Learning outcome updated successfully!');
  };

  // Handle Delete CLO
  const handleDeleteClo = async (cloId: number | string) => {
    if (!window.confirm('Are you sure you want to delete this learning outcome?')) return;
    try {
      setDeletingCloId(cloId);
      await learningOutcomeService.delete(cloId);
      setLearningOutcomes(learningOutcomes.filter((clo) => clo.id !== cloId));
      showNotification('Learning outcome deleted successfully!');
    } catch (err: unknown) {
      if (err instanceof Error) setError(err.message);
    } finally {
      setDeletingCloId(null);
    }
  };

  // Handle Material Upload
  const handleUploadMaterial = async (formData: FormData) => {
    if (!id) return;
    const res = await courseMaterialService.upload(id, formData);
    setMaterials([res.data, ...materials]);
    showNotification('Course material uploaded successfully!');
  };

  // Handle Material Download
  const handleDownloadMaterial = async (material: CourseMaterial) => {
    try {
      await courseMaterialService.download(material.id, material.file_name);
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      }
    }
  };

  // Handle Material Delete
  const handleDeleteMaterial = async (materialId: number | string) => {
    if (!window.confirm('Are you sure you want to delete this course material file?')) return;
    try {
      setDeletingMaterialId(materialId);
      await courseMaterialService.delete(materialId);
      setMaterials(materials.filter((m) => m.id !== materialId));
      showNotification('Material removed successfully!');
    } catch (err: unknown) {
      if (err instanceof Error) setError(err.message);
    } finally {
      setDeletingMaterialId(null);
    }
  };

  const formatFileSize = (bytes?: number) => {
    if (!bytes) return '0 B';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
  };

  if (isLoading) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[400px] space-y-4">
        <Loader2 className="w-8 h-8 animate-spin text-[#111111] dark:text-white" />
        <p className="text-sm text-[#737373]">Loading course information...</p>
      </div>
    );
  }

  if (error || !course) {
    return (
      <div className="space-y-4">
        <Link to="/courses" className="inline-flex items-center gap-2 text-xs font-semibold text-[#737373] hover:text-[#111111] dark:hover:text-white">
          <ArrowLeft className="w-4 h-4" /> Back to Courses
        </Link>
        <div className="p-6 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900 rounded-xl space-y-3">
          <div className="flex items-center gap-2 text-red-600 dark:text-red-400 font-bold">
            <AlertCircle className="w-5 h-5" />
            <span>Unable to load course</span>
          </div>
          <p className="text-sm text-red-700 dark:text-red-300">
            {error || 'Course not found or you do not have permission to view it.'}
          </p>
          <Button variant="outline" size="sm" onClick={() => fetchCourseDetails()}>
            Try Again
          </Button>
        </div>
      </div>
    );
  }

  const courseCode = course.course_code || course.code;
  const courseName = course.course_name || course.title;
  const academicYear = course.academic_year || (course.year ? `${course.year}-${course.year + 1}` : '2025-2026');
  const credits = course.credits || course.creditHours || 3;

  return (
    <div className="space-y-6">
      {/* Toast notification */}
      {successMessage && (
        <div className="p-4 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 rounded-xl flex items-center gap-3 text-emerald-700 dark:text-emerald-300 text-sm font-medium animate-in fade-in slide-in-from-top-2">
          <CheckCircle2 className="w-5 h-5 shrink-0" />
          <span>{successMessage}</span>
        </div>
      )}

      {/* Breadcrumb & Top Actions */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div className="space-y-1">
          <div className="flex items-center gap-2 text-xs text-[#737373]">
            <Link to="/courses" className="hover:text-[#111111] dark:hover:text-white transition-colors">
              Courses
            </Link>
            <span>/</span>
            <span className="font-mono font-semibold text-[#111111] dark:text-white">{courseCode}</span>
          </div>
          <h1 className="text-2xl font-bold text-[#111111] dark:text-white">{courseName}</h1>
        </div>

        <div className="flex items-center gap-2">
          <Link to={`/courses/${course.id}/question-generator`} data-testid="question-generator-link">
            <Button variant="outline" size="sm" leftIcon={<Sparkles className="w-3.5 h-3.5" />}>Generate Questions</Button>
          </Link>
          <Link to={`/courses/${course.id}/chat`} data-testid="document-chat-link">
            <Button variant="outline" size="sm" leftIcon={<MessageSquareText className="w-3.5 h-3.5" />}>Document Chat</Button>
          </Link>
          <Link to={`/courses/${course.id}/co-po-mapping`} data-testid="co-po-link">
            <Button variant="outline" size="sm" leftIcon={<Grid3X3 className="w-3.5 h-3.5" />}>CO / PO Mapping</Button>
          </Link>
          <Button
            variant="outline"
            size="sm"
            leftIcon={<Edit className="w-3.5 h-3.5" />}
            onClick={() => setIsEditCourseOpen(true)}
          >
            Edit Course
          </Button>
          <Button
            variant="outline"
            size="sm"
            className="text-red-600 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-950/30 border-red-200 dark:border-red-900/50"
            leftIcon={deletingCourse ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Trash2 className="w-3.5 h-3.5" />}
            onClick={handleDeleteCourse}
            disabled={deletingCourse}
          >
            Delete
          </Button>
        </div>
      </div>

      {/* Course Overview Card */}
      <Card variant="default" className="p-6 space-y-6">
        <div className="flex flex-wrap items-center gap-3">
          <Badge variant="outline" className="font-mono font-bold text-sm bg-[#F7F7F5] dark:bg-[#2C2C2E]">
            {courseCode}
          </Badge>
          <Badge variant="neutral" className="text-xs">
            {course.semester} {academicYear}
          </Badge>
          <Badge variant="neutral" className="text-xs">
            {credits} Credit {credits === 1 ? 'Unit' : 'Units'}
          </Badge>
          {course.status && (
            <Badge
              variant={course.status === 'active' ? 'default' : 'neutral'}
              className="text-xs capitalize"
            >
              {course.status}
            </Badge>
          )}
        </div>

        {course.description && (
          <div className="space-y-1">
            <h3 className="text-xs font-bold uppercase tracking-wider text-[#737373]">Course Description</h3>
            <p className="text-sm text-[#262626] dark:text-[#E5E5E5] leading-relaxed whitespace-pre-line">
              {course.description}
            </p>
          </div>
        )}

        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 pt-4 border-t border-[#E5E5E5] dark:border-[#2C2C2E] text-center">
          <div className="p-3 bg-[#F7F7F5] dark:bg-[#2C2C2E] rounded-xl">
            <span className="text-[10px] uppercase font-semibold text-[#737373] block">Learning Outcomes</span>
            <span className="text-lg font-bold text-[#111111] dark:text-white flex items-center justify-center gap-1.5 mt-1">
              <Sparkles className="w-4 h-4 text-[#737373]" /> {learningOutcomes.length}
            </span>
          </div>
          <div className="p-3 bg-[#F7F7F5] dark:bg-[#2C2C2E] rounded-xl">
            <span className="text-[10px] uppercase font-semibold text-[#737373] block">Course Materials</span>
            <span className="text-lg font-bold text-[#111111] dark:text-white flex items-center justify-center gap-1.5 mt-1">
              <Layers className="w-4 h-4 text-[#737373]" /> {materials.length}
            </span>
          </div>
          <div className="p-3 bg-[#F7F7F5] dark:bg-[#2C2C2E] rounded-xl">
            <span className="text-[10px] uppercase font-semibold text-[#737373] block">Assessments</span>
            <span className="text-lg font-bold text-[#111111] dark:text-white flex items-center justify-center gap-1.5 mt-1">
              <FileCheck2 className="w-4 h-4 text-[#737373]" /> {course.assessments_count ?? course.assessmentCount ?? 0}
            </span>
          </div>
          <div className="p-3 bg-[#F7F7F5] dark:bg-[#2C2C2E] rounded-xl">
            <span className="text-[10px] uppercase font-semibold text-[#737373] block">Term / Year</span>
            <span className="text-xs font-bold text-[#111111] dark:text-white flex items-center justify-center gap-1.5 mt-2">
              <Calendar className="w-3.5 h-3.5 text-[#737373]" /> {course.semester} {academicYear}
            </span>
          </div>
        </div>
      </Card>

      {/* Two Column Section: Learning Outcomes & Course Materials */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Section 1: Learning Outcomes */}
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <div className="w-7 h-7 rounded-lg bg-[#F7F7F5] dark:bg-[#2C2C2E] flex items-center justify-center text-[#111111] dark:text-white">
                <Sparkles className="w-4 h-4" />
              </div>
              <h2 className="text-base font-bold text-[#111111] dark:text-white">Course Learning Outcomes</h2>
              <Badge variant="outline" className="text-[10px] font-mono">{learningOutcomes.length}</Badge>
            </div>
            <Button
              variant="primary"
              size="sm"
              leftIcon={<Plus className="w-3.5 h-3.5" />}
              onClick={() => setIsAddCloOpen(true)}
            >
              Add Outcome
            </Button>
          </div>

          {learningOutcomes.length === 0 ? (
            <Card variant="default" className="p-8 text-center space-y-3">
              <Sparkles className="w-8 h-8 text-[#737373] mx-auto opacity-50" />
              <div className="space-y-1">
                <p className="text-sm font-bold text-[#111111] dark:text-white">No learning outcomes defined</p>
                <p className="text-xs text-[#737373]">
                  Add CLOs to map exam questions and measure student cognitive achievements.
                </p>
              </div>
              <Button
                variant="outline"
                size="sm"
                leftIcon={<Plus className="w-3.5 h-3.5" />}
                onClick={() => setIsAddCloOpen(true)}
              >
                Define First Outcome
              </Button>
            </Card>
          ) : (
            <div className="space-y-3">
              {learningOutcomes.map((clo) => (
                <Card
                  key={clo.id}
                  variant="default"
                  className="p-4 space-y-2 hover:border-[#111111] dark:hover:border-white transition-colors"
                >
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                      <span className="font-mono font-bold text-sm text-[#111111] dark:text-white">
                        {clo.code}
                      </span>
                      <Badge variant="neutral" className="text-[10px]">
                        Bloom: {clo.cognitive_level || clo.bloomLevel || 'Understand'}
                      </Badge>
                    </div>
                    <div className="flex items-center gap-1">
                      <button
                        type="button"
                        onClick={() => setEditingClo(clo)}
                        className="p-1 rounded text-[#737373] hover:text-[#111111] dark:hover:text-white hover:bg-[#F7F7F5] dark:hover:bg-[#2C2C2E]"
                        title="Edit outcome"
                      >
                        <Edit className="w-3.5 h-3.5" />
                      </button>
                      <button
                        type="button"
                        onClick={() => handleDeleteClo(clo.id)}
                        disabled={deletingCloId === clo.id}
                        className="p-1 rounded text-red-500 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-950/30"
                        title="Delete outcome"
                      >
                        {deletingCloId === clo.id ? (
                          <Loader2 className="w-3.5 h-3.5 animate-spin" />
                        ) : (
                          <Trash2 className="w-3.5 h-3.5" />
                        )}
                      </button>
                    </div>
                  </div>
                  <p className="text-xs text-[#262626] dark:text-[#D4D4D4] leading-relaxed">
                    {clo.description}
                  </p>
                </Card>
              ))}
            </div>
          )}
        </div>

        {/* Section 2: Course Materials & Reference Files */}
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <div className="w-7 h-7 rounded-lg bg-[#F7F7F5] dark:bg-[#2C2C2E] flex items-center justify-center text-[#111111] dark:text-white">
                <FileText className="w-4 h-4" />
              </div>
              <h2 className="text-base font-bold text-[#111111] dark:text-white">Course Materials</h2>
              <Badge variant="outline" className="text-[10px] font-mono">{materials.length}</Badge>
            </div>
            <Button
              variant="outline"
              size="sm"
              leftIcon={<UploadCloud className="w-3.5 h-3.5" />}
              onClick={() => setIsUploadMaterialOpen(true)}
            >
              Upload Material
            </Button>
          </div>

          {materials.length === 0 ? (
            <Card variant="default" className="p-8 text-center space-y-3">
              <UploadCloud className="w-8 h-8 text-[#737373] mx-auto opacity-50" />
              <div className="space-y-1">
                <p className="text-sm font-bold text-[#111111] dark:text-white">No materials uploaded yet</p>
                <p className="text-xs text-[#737373]">
                  Upload syllabi, lecture guidelines, reference books, and past slides (PDF, DOCX, TXT).
                </p>
              </div>
              <Button
                variant="outline"
                size="sm"
                leftIcon={<UploadCloud className="w-3.5 h-3.5" />}
                onClick={() => setIsUploadMaterialOpen(true)}
              >
                Upload Course Syllabus
              </Button>
            </Card>
          ) : (
            <div className="space-y-3">
              {materials.map((mat) => (
                <Card
                  key={mat.id}
                  variant="default"
                  className="p-4 space-y-2 hover:border-[#111111] dark:hover:border-white transition-colors"
                >
                  <div className="flex items-start justify-between gap-2">
                    <div className="flex items-start gap-3">
                      <div className="w-8 h-8 rounded-lg bg-[#F7F7F5] dark:bg-[#2C2C2E] border border-[#E5E5E5] dark:border-[#3A3A3C] flex items-center justify-center text-[#111111] dark:text-white shrink-0 mt-0.5">
                        <FileText className="w-4 h-4" />
                      </div>
                      <div>
                        <h4 className="text-sm font-bold text-[#111111] dark:text-white">{mat.title}</h4>
                        <p className="text-xs text-[#737373] font-mono mt-0.5">
                          {mat.file_name} • {formatFileSize(mat.file_size)}
                        </p>
                        {mat.description && (
                          <p className="text-xs text-[#262626] dark:text-[#D4D4D4] mt-1">
                            {mat.description}
                          </p>
                        )}
                      </div>
                    </div>

                    <div className="flex items-center gap-1 shrink-0">
                      <button
                        type="button"
                        onClick={() => handleDownloadMaterial(mat)}
                        className="p-1.5 rounded-lg text-[#111111] dark:text-white bg-[#F7F7F5] dark:bg-[#2C2C2E] hover:bg-[#E5E5E5] dark:hover:bg-[#3A3A3C] transition-colors"
                        title="Download file"
                      >
                        <Download className="w-3.5 h-3.5" />
                      </button>
                      <button
                        type="button"
                        onClick={() => handleDeleteMaterial(mat.id)}
                        disabled={deletingMaterialId === mat.id}
                        className="p-1.5 rounded-lg text-red-500 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-950/30 transition-colors"
                        title="Delete material"
                      >
                        {deletingMaterialId === mat.id ? (
                          <Loader2 className="w-3.5 h-3.5 animate-spin" />
                        ) : (
                          <Trash2 className="w-3.5 h-3.5" />
                        )}
                      </button>
                    </div>
                  </div>
                </Card>
              ))}
            </div>
          )}
        </div>
      </div>

      {/* Section 3: Processed Academic Documents (STEP 08) */}
      <div className="space-y-4 pt-4 border-t border-slate-200 dark:border-slate-700/80">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            <div className="w-7 h-7 rounded-lg bg-[#F7F7F5] dark:bg-[#2C2C2E] flex items-center justify-center text-[#111111] dark:text-white">
              <FileText className="w-4 h-4" />
            </div>
            <h2 className="text-base font-bold text-[#111111] dark:text-white">Extracted Academic Documents</h2>
            <Badge variant="outline" className="text-[10px] font-mono">{documents.length}</Badge>
          </div>
          <Button
            variant="primary"
            size="sm"
            leftIcon={<UploadCloud className="w-3.5 h-3.5" />}
            onClick={() => setIsUploadDocOpen(true)}
          >
            Upload Document
          </Button>
        </div>

        <DocumentList
          documents={documents}
          isLoading={isLoadingDocs}
          onOpenUpload={() => setIsUploadDocOpen(true)}
          onDownload={handleDownloadDocument}
          onDelete={handleDeleteDocument}
          onReprocess={handleReprocessDocument}
        />
      </div>

      {/* Edit Course Modal */}
      <CourseModal
        isOpen={isEditCourseOpen}
        onClose={() => setIsEditCourseOpen(false)}
        onSubmit={handleUpdateCourse}
        course={course}
        title="Edit Course Information"
      />

      {/* Add Learning Outcome Modal */}
      <LearningOutcomeModal
        isOpen={isAddCloOpen}
        onClose={() => setIsAddCloOpen(false)}
        onSubmit={handleAddClo}
        defaultCode={`CLO-${learningOutcomes.length + 1}`}
      />

      {/* Edit Learning Outcome Modal */}
      <LearningOutcomeModal
        isOpen={Boolean(editingClo)}
        onClose={() => setEditingClo(null)}
        onSubmit={handleUpdateClo}
        outcome={editingClo}
      />

      {/* Upload Material Modal */}
      <MaterialUploadModal
        isOpen={isUploadMaterialOpen}
        onClose={() => setIsUploadMaterialOpen(false)}
        onSubmit={handleUploadMaterial}
      />

      {/* Upload Academic Document Modal (STEP 08) */}
      {id && (
        <DocumentUploadModal
          isOpen={isUploadDocOpen}
          onClose={() => setIsUploadDocOpen(false)}
          courseId={id}
          onUploadSuccess={() => {}}
          onSubmitUpload={handleUploadDocument}
        />
      )}
    </div>
  );
};
