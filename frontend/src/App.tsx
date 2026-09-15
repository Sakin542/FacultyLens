import React from 'react';
import { BrowserRouter } from 'react-router-dom';
import { AppRoutes } from '@/routes/AppRoutes';
import { ThemeProvider } from '@/context/ThemeContext';
import { AuthProvider, useAuth } from '@/context/AuthContext';
import { SessionSplash } from '@/components/common/ProtectedRoute';

import { NotificationProvider, useNotificationContext } from '@/context/NotificationContext';
import { NotificationToastContainer } from '@/components/notifications/NotificationToast';

const NotificationToasts: React.FC = () => {
  const { toasts, dismissToast } = useNotificationContext();
  return <NotificationToastContainer toasts={toasts} onDismiss={dismissToast} />;
};

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
            <NotificationProvider>
              <AppRoutes />
              <NotificationToasts />
            </NotificationProvider>
          </BrowserRouter>
        </BootGate>
      </AuthProvider>
    </ThemeProvider>
  );
};

export default App;
