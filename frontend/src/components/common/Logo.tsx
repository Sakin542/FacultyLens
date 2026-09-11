import React from 'react';
import { cn } from '@/utils/cn';

export interface LogoProps {
  size?: 'sm' | 'md' | 'lg' | 'xl';
  variant?: 'dark' | 'light' | 'auto';
  showSubtitle?: boolean;
  animated?: boolean;
  className?: string;
}

export const Logo: React.FC<LogoProps> = ({
  size = 'md',
  variant = 'auto',
  showSubtitle = false,
  animated = true,
  className,
}) => {
  const sizeMap = {
    sm: {
      container: 'h-8',
      iconBox: 'w-7 h-7',
      svg: 28,
      facultyText: 'text-base',
      lensText: 'text-base',
      sub: 'text-[8.5px] -mt-0.5 tracking-widest',
      gap: 'gap-2.5',
      badge: 'px-1 py-0 text-[8px]',
    },
    md: {
      container: 'h-10',
      iconBox: 'w-8 h-8',
      svg: 32,
      facultyText: 'text-lg',
      lensText: 'text-lg',
      sub: 'text-[9.5px] -mt-0.5 tracking-widest',
      gap: 'gap-3',
      badge: 'px-1.5 py-0.5 text-[9px]',
    },
    lg: {
      container: 'h-12',
      iconBox: 'w-11 h-11',
      svg: 44,
      facultyText: 'text-2xl',
      lensText: 'text-2xl',
      sub: 'text-xs tracking-widest',
      gap: 'gap-3.5',
      badge: 'px-2 py-0.5 text-[10px]',
    },
    xl: {
      container: 'h-16',
      iconBox: 'w-14 h-14',
      svg: 56,
      facultyText: 'text-3xl sm:text-4xl',
      lensText: 'text-3xl sm:text-4xl',
      sub: 'text-xs tracking-[0.2em]',
      gap: 'gap-4',
      badge: 'px-2.5 py-1 text-xs',
    },
  };

  const isLight = variant === 'light'; // Light text on dark bg

  return (
    <div className={cn('inline-flex items-center group select-none', sizeMap[size].gap, className)}>
      {/* ◉ New Animated AI Prism Lens Mark */}
      <div
        className={cn(
          'relative shrink-0 rounded-xl flex items-center justify-center transition-all duration-500 overflow-hidden shadow-subtle',
          sizeMap[size].iconBox,
          isLight
            ? 'bg-[#181818] border border-[#2E2E2E] group-hover:border-[#FFFFFF]/60 group-hover:shadow-[0_0_20px_rgba(255,255,255,0.15)]'
            : 'bg-sage-700 border border-sage-600 group-hover:border-sage-700 group-hover:shadow-[0_0_20px_rgba(0,0,0,0.2)]'
        )}
      >
        <svg
          width={sizeMap[size].svg}
          height={sizeMap[size].svg}
          viewBox="0 0 48 48"
          fill="none"
          xmlns="http://www.w3.org/2000/svg"
          className="w-full h-full p-1 transition-transform duration-500 group-hover:scale-110"
        >
          {/* Background Ambient Radial Glow */}
          <circle
            cx="24"
            cy="24"
            r="16"
            className={cn(
              'opacity-20 transition-opacity duration-300 group-hover:opacity-40',
              isLight ? 'fill-white' : 'fill-sage-500'
            )}
          />

          {/* Outer Rotating Hexagon Prism Boundary */}
          <polygon
            points="24,4 41,14 41,34 24,44 7,34 7,14"
            stroke="currentColor"
            strokeWidth="1.5"
            strokeLinejoin="round"
            className={cn(
              isLight ? 'text-[#888888]' : 'text-sage-400',
              'origin-center transition-all duration-700',
              animated && 'group-hover:rotate-45'
            )}
          />

          {/* Internal Geometric Facet Lines (Optical Prism Triangulation) */}
          <path
            d="M24 4L24 24M41 14L24 24M41 34L24 24M24 44L24 24M7 34L24 24M7 14L24 24"
            stroke="currentColor"
            strokeWidth="1.2"
            strokeLinecap="round"
            className={cn(
              isLight ? 'text-[#555555]' : 'text-sage-600',
              'transition-all duration-300 group-hover:stroke-[1.6]'
            )}
          />

          {/* Concentric Floating Iris Ring */}
          <circle
            cx="24"
            cy="24"
            r="9"
            stroke="currentColor"
            strokeWidth="1.5"
            strokeDasharray="4 3"
            className={cn(
              isLight ? 'text-white' : 'text-[#FFFFFF]',
              'origin-center',
              animated && 'animate-[spin_12s_linear_infinite] group-hover:animate-[spin_4s_linear_infinite]'
            )}
          />

          {/* Central Neural Refraction Diamond (AI Core) */}
          <polygon
            points="24,18 29,24 24,30 19,24"
            fill={isLight ? '#FFFFFF' : '#FFFFFF'}
            className={cn(
              'origin-center transition-transform duration-300',
              animated && 'animate-pulse group-hover:scale-125'
            )}
          />

          {/* Super Focal Core Point */}
          <circle
            cx="24"
            cy="24"
            r="2"
            fill="#2F3E2E"
          />

          {/* Laser Scanning Sweep Bar */}
          <line
            x1="10"
            y1="24"
            x2="38"
            y2="24"
            stroke={isLight ? '#FFFFFF' : '#FFFFFF'}
            strokeWidth="1.5"
            strokeLinecap="round"
            className={cn(
              'opacity-0 group-hover:opacity-100 transition-all duration-500 origin-center',
              animated && 'group-hover:animate-[spin_2s_linear_infinite]'
            )}
          />
        </svg>

        {/* Diagonal Light Glint Sweep */}
        <div className="absolute inset-0 -translate-x-full group-hover:translate-x-full transition-transform duration-1000 bg-gradient-to-r from-transparent via-white/20 to-transparent pointer-events-none" />
      </div>

      {/* ✦ Animated & Stylish Brand Typography */}
      <div className="flex flex-col text-left justify-center">
        <div className="flex items-center tracking-tight leading-none">
          {/* "Faculty" with animated metallic shimmer effect */}
          <span
            className={cn(
              sizeMap[size].facultyText,
              'font-extrabold tracking-tighter font-sans transition-all duration-300',
              animated
                ? isLight
                  ? 'animate-font-shimmer-light'
                  : 'animate-font-shimmer-dark'
                : isLight
                ? 'text-white'
                : 'text-sage-800'
            )}
          >
            Faculty
          </span>

          {/* "Lens" with stylish high-contrast font treatment & gradient shimmer */}
          <span
            className={cn(
              sizeMap[size].lensText,
              'font-light italic ml-1 tracking-tight font-sans transition-all duration-300',
              isLight
                ? 'text-white/90 group-hover:text-white'
                : 'text-[#404040] group-hover:text-sage-800'
            )}
          >
            Lens
          </span>

          {/* Pulsing Emerald AI Neural Beacon */}
          <span className="relative flex h-2 w-2 ml-1.5 mb-1">
            <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-[#16A34A] opacity-75" />
            <span className="relative inline-flex rounded-full h-2 w-2 bg-[#16A34A]" />
          </span>
        </div>

        {showSubtitle && (
          <div className="flex items-center gap-1.5 pt-0.5">
            <span
              className={cn(
                sizeMap[size].sub,
                'font-mono uppercase font-bold transition-colors',
                isLight ? 'text-[#888888]' : 'text-sage-500'
              )}
            >
              Academic Intelligence Suite
            </span>
          </div>
        )}
      </div>
    </div>
  );
};
