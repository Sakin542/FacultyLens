import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Card, CardTitle, CardDescription } from '@/components/common/Card';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import { Input } from '@/components/common/Input';
import { mockHistoryRecords } from '@/utils/mockData';
import {
  Search,
  Eye,
  Download,
} from 'lucide-react';

export const History: React.FC = () => {
  const navigate = useNavigate();
  const [records] = useState(mockHistoryRecords);
  const [searchQuery, setSearchQuery] = useState('');

  const filteredRecords = records.filter(
    (r) =>
      r.assessmentTitle.toLowerCase().includes(searchQuery.toLowerCase()) ||
      r.courseCode.toLowerCase().includes(searchQuery.toLowerCase()) ||
      r.courseTitle.toLowerCase().includes(searchQuery.toLowerCase())
  );

  return (
    <div className="space-y-6">
      {/* Top Bar Controls */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div className="relative flex-1 max-w-md">
          <Input
            placeholder="Search analysis history by course or exam title..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            leftIcon={<Search className="w-4 h-4" />}
          />
        </div>

        <Button
          variant="outline"
          size="sm"
          leftIcon={<Download className="w-4 h-4" />}
          onClick={() => alert('Exporting accreditation history report summary...')}
        >
          Export Audit Log
        </Button>
      </div>

      {/* History Table Card */}
      <Card padding="none" className="overflow-hidden">
        <div className="p-6 border-b border-[#E5E5E5] flex items-center justify-between">
          <div>
            <CardTitle>Assessment Quality Audit Log</CardTitle>
            <CardDescription>Historical AI evaluations, coverage metrics, and past version reviews</CardDescription>
          </div>
          <Badge variant="outline" className="font-mono">{records.length} Recorded Analyses</Badge>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead className="bg-[#F7F7F5] border-b border-[#E5E5E5] text-[#737373] uppercase tracking-wider font-semibold">
              <tr>
                <th className="px-6 py-3.5">Assessment & Course</th>
                <th className="px-6 py-3.5">Quality Score</th>
                <th className="px-6 py-3.5">Topic Coverage</th>
                <th className="px-6 py-3.5">LO Alignment</th>
                <th className="px-6 py-3.5">Analysis Date</th>
                <th className="px-6 py-3.5">Status</th>
                <th className="px-6 py-3.5 text-right">Action</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[#E5E5E5]">
              {filteredRecords.map((rec) => (
                <tr key={rec.id} className="hover:bg-[#F7F7F5] transition-colors">
                  <td className="px-6 py-4">
                    <div className="font-semibold text-[#111111]">{rec.assessmentTitle}</div>
                    <div className="text-[11px] text-[#737373]">
                      <span className="font-mono font-medium text-[#262626]">{rec.courseCode}</span> • {rec.courseTitle}
                    </div>
                  </td>

                  <td className="px-6 py-4">
                    <div className="flex items-center gap-2">
                      <span className="font-mono font-bold text-sm text-[#111111]">
                        {rec.qualityScore}%
                      </span>
                      <div className="w-12 bg-[#E5E5E5] h-1.5 rounded-full overflow-hidden hidden sm:block">
                        <div
                          className="bg-[#16A34A] h-full rounded-full"
                          style={{ width: `${rec.qualityScore}%` }}
                        />
                      </div>
                    </div>
                  </td>

                  <td className="px-6 py-4 font-mono font-medium text-[#262626]">
                    {rec.topicCoverageScore}%
                  </td>

                  <td className="px-6 py-4 font-mono font-medium text-[#262626]">
                    {rec.outcomeAlignmentScore}%
                  </td>

                  <td className="px-6 py-4 font-mono text-[11px] text-[#737373]">
                    {rec.analysisDate}
                  </td>

                  <td className="px-6 py-4">
                    <Badge variant={rec.status as any} dot>
                      {rec.status}
                    </Badge>
                  </td>

                  <td className="px-6 py-4 text-right">
                    <div className="flex items-center justify-end gap-2">
                      <Button
                        variant="outline"
                        size="sm"
                        leftIcon={<Eye className="w-3.5 h-3.5" />}
                        onClick={() => navigate('/analysis')}
                      >
                        View
                      </Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </div>
  );
};

