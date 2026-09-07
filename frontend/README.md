# FacultyLens Frontend

React + TypeScript + Vite frontend for FacultyLens — AI-Powered Academic Decision Support System for University Faculty.

## Technologies

- **React 18** (Modern functional components)
- **TypeScript 5** (Strict type definitions)
- **Vite 6** (Fast build and HMR toolchain)
- **Tailwind CSS 3** (Custom Black & White Academic Intelligence Design Theme)
- **React Router 6** (Declarative client-side routing)
- **Lucide React** (Consistent minimal icon system)

## Theme & Design System

The application implements the **Minimal Black & White Academic Intelligence Theme**:
- **Black**: `#111111` (Navigation, headings, primary actions)
- **White**: `#FFFFFF` (Card surfaces, clean containers)
- **Off White**: `#F7F7F5` (Background)
- **Light Gray**: `#E5E5E5` (Borders, dividing rules)
- **Gray**: `#737373` (Labels, captions, secondary typography)
- **Dark Gray**: `#262626` (Sidebar, secondary accents)

Subtle semantic indicators:
- **Green** (`#16A34A`): Good / High Alignment
- **Amber** (`#D97706`): Attention / Moderate Coverage
- **Red** (`#DC2626`): Critical / Duplicate Question Alert

## Available Routes

| Route | Description | Layout |
| :--- | :--- | :--- |
| `/` | Landing page with feature highlights and live AI demo preview | Public |
| `/login` | Faculty authentication entry | Public |
| `/register` | Institutional registration form | Public |
| `/forgot-password` | Password reset request | Public |
| `/dashboard` | Executive academic dashboard with KPIs and distribution metrics | Dashboard |
| `/courses` | Course catalog, learning outcomes (CLOs), and details | Dashboard |
| `/assessments` | Exams, quizzes, and paper upload queue | Dashboard |
| `/analysis` | AI Assessment analysis report with findings and recommendations | Dashboard |
| `/history` | Historical audit logs of past evaluations | Dashboard |
| `/settings` | Faculty profile, department metadata, and security | Dashboard |
| `*` | 404 Not Found fallback | Standalone |

## Getting Started

### 1. Install Dependencies

```bash
npm install
```

### 2. Run Development Server

```bash
npm run dev
```

The app will be accessible at `http://localhost:3000`.

### 3. Build for Production

```bash
npm run build
```

The compiled assets will be output in `dist/`.

## Project Structure

```text
frontend/
├── public/
├── src/
│   ├── assets/
│   ├── components/
│   │   ├── common/
│   │   │   ├── Badge.tsx
│   │   │   ├── Button.tsx
│   │   │   ├── Card.tsx
│   │   │   ├── EmptyState.tsx
│   │   │   └── Input.tsx
│   │   ├── dashboard/
│   │   │   ├── ProgressBar.tsx
│   │   │   └── StatCard.tsx
│   │   └── layout/
│   │       ├── DashboardLayout.tsx
│   │       ├── Navbar.tsx
│   │       ├── PublicLayout.tsx
│   │       └── Sidebar.tsx
│   ├── pages/
│   │   ├── Analysis.tsx
│   │   ├── Assessments.tsx
│   │   ├── Courses.tsx
│   │   ├── Dashboard.tsx
│   │   ├── ForgotPassword.tsx
│   │   ├── History.tsx
│   │   ├── Home.tsx
│   │   ├── Login.tsx
│   │   ├── NotFound.tsx
│   │   ├── Register.tsx
│   │   └── Settings.tsx
│   ├── routes/
│   │   └── AppRoutes.tsx
│   ├── services/
│   │   └── api.ts
│   ├── types/
│   │   └── index.ts
│   ├── utils/
│   │   ├── cn.ts
│   │   └── mockData.ts
│   ├── App.tsx
│   ├── index.css
│   └── main.tsx
├── .env.example
├── .gitignore
├── index.html
├── package.json
├── postcss.config.js
├── tailwind.config.js
├── tsconfig.json
├── tsconfig.node.json
├── vite.config.ts
└── README.md
```

