import React from 'react';
import { BrowserRouter } from 'react-router-dom';
import { AppRoutes } from '@/routes/AppRoutes';
import { ThemeProvider } from '@/context/ThemeContext';
import { AuthProvider, useAuth } from '@/context/AuthContext';
import { SessionSplash } from '@/components/common/ProtectedRoute';

const BootGate: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { loading } = useAuth();
  return loading ? <SessionSplash /> : <>{children}</>;
};

export const App: React.FC = () => {
  return (
    <ThemeProvider>
      <AuthProvider>
        <BootGate>
          <BrowserRouter
            future={{
              v7_startTransition: true,
              v7_relativeSplatPath: true,
            }}
          >
            <AppRoutes />
          </BrowserRouter>
        </BootGate>
      </AuthProvider>
    </ThemeProvider>
  );
};

export default App;
