export interface RegisteredUser {
  id: string;
  fullName: string;
  email: string;
  department: string;
  designation: string;
  password?: string;
  createdAt?: string;
}

const USERS_STORAGE_KEY = 'facultylens_registered_users';
const CURRENT_USER_KEY = 'facultylens_current_user';
const TOKEN_KEY = 'facultylens_token';

/**
 * Retrieve all registered users stored in localStorage
 */
export const getRegisteredUsers = (): RegisteredUser[] => {
  try {
    const raw = localStorage.getItem(USERS_STORAGE_KEY);
    if (!raw) return [];
    return JSON.parse(raw);
  } catch {
    return [];
  }
};

/**
 * Register a new faculty user
 */
export const registerUser = (user: RegisteredUser): { success: boolean; message?: string } => {
  const users = getRegisteredUsers();
  const existing = users.find((u) => u.email.trim().toLowerCase() === user.email.trim().toLowerCase());
  
  if (existing) {
    return {
      success: false,
      message: 'This university email is already registered. Please sign in instead.',
    };
  }

  users.push({
    ...user,
    id: user.id || `faculty-${Date.now()}`,
    createdAt: new Date().toISOString(),
  });

  localStorage.setItem(USERS_STORAGE_KEY, JSON.stringify(users));
  return { success: true };
};

/**
 * Authenticate login against registered users
 */
export const authenticateUser = (
  email: string,
  password: string
): { success: boolean; user?: RegisteredUser; message?: string } => {
  const users = getRegisteredUsers();
  const normalizedEmail = email.trim().toLowerCase();
  const user = users.find((u) => u.email.trim().toLowerCase() === normalizedEmail);

  if (!user) {
    return {
      success: false,
      message: 'No registered faculty account found for this email. Only registered university faculty can access the dashboard.',
    };
  }

  if (user.password && user.password !== password) {
    return {
      success: false,
      message: 'Invalid password. Please verify your credentials and try again.',
    };
  }

  const sessionToken = `facultylens_session_${Date.now()}_${Math.random().toString(36).substring(2, 9)}`;
  localStorage.setItem(TOKEN_KEY, sessionToken);
  localStorage.setItem(CURRENT_USER_KEY, JSON.stringify(user));
  localStorage.setItem('facultylens_user_email', user.email);
  localStorage.setItem('facultylens_user_name', user.fullName);

  return { success: true, user };
};

/**
 * Get current authenticated user details
 */
export const getCurrentUser = (): RegisteredUser | null => {
  try {
    const raw = localStorage.getItem(CURRENT_USER_KEY);
    if (raw) return JSON.parse(raw);
    const email = localStorage.getItem('facultylens_user_email');
    if (email) {
      const users = getRegisteredUsers();
      const found = users.find((u) => u.email.trim().toLowerCase() === email.trim().toLowerCase());
      if (found) return found;
    }
  } catch {
    // ignore
  }
  return null;
};

/**
 * Check if session token is present
 */
export const isAuthenticated = (): boolean => {
  const token = localStorage.getItem(TOKEN_KEY);
  return Boolean(token);
};

/**
 * Log out current user
 */
export const logoutUser = (): void => {
  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(CURRENT_USER_KEY);
  localStorage.removeItem('facultylens_user_email');
  localStorage.removeItem('facultylens_user_name');
};
