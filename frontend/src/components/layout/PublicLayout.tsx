import React from 'react';
import { Outlet } from 'react-router-dom';
import { Navbar } from './Navbar';
import { Footer } from './Footer';
import { PageTransition } from '@/components/common/PageTransition';

export const PublicLayout: React.FC = () => {
  return (
    <div className="min-h-screen flex flex-col bg-sage-50 text-sage-800">
      <Navbar />
      <main className="flex-1">
        <PageTransition><Outlet /></PageTransition>
      </main>
      <Footer />
    </div>
  );
};
