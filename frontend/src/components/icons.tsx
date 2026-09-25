import type { SVGProps } from 'react'

type P = SVGProps<SVGSVGElement> & { size?: number }

function Svg({ size = 22, strokeWidth = 1.9, children, ...rest }: P) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={strokeWidth}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      {...rest}
    >
      {children}
    </svg>
  )
}

export const IconHome = (p: P) => <Svg {...p}><path d="M4 10.5 12 4l8 6.5V20h-5v-5H9v5H4z" /></Svg>
export const IconSearch = (p: P) => <Svg {...p}><circle cx="11" cy="11" r="7" /><path d="m20 20-4-4" /></Svg>
export const IconSubs = (p: P) => <Svg {...p}><rect x="3" y="6" width="18" height="13" rx="3" /><path d="M7 3h10" /></Svg>
export const IconBell = (p: P) => <Svg {...p}><path d="M6 16V11a6 6 0 1 1 12 0v5l2 2H4z" /><path d="M10 21h4" /></Svg>
export const IconUser = (p: P) => <Svg {...p}><circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" /></Svg>
export const IconCheck = (p: P) => <Svg strokeWidth={2.4} {...p}><path d="m5 12.5 4.5 4.5L19 7.5" /></Svg>
export const IconShare = (p: P) => <Svg strokeWidth={2} {...p}><path d="M12 3v13" /><path d="m7 8 5-5 5 5" /><path d="M5 14v6h14v-6" /></Svg>
export const IconLock = (p: P) => <Svg strokeWidth={2.2} {...p}><rect x="5" y="11" width="14" height="10" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" /></Svg>
export const IconEye = (p: P) => <Svg strokeWidth={2} {...p}><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z" /><circle cx="12" cy="12" r="3" /></Svg>
export const IconEyeOff = (p: P) => <Svg strokeWidth={2} {...p}><path d="M3 3l18 18" /><path d="M10.6 5.1A10.4 10.4 0 0 1 12 5c6 0 10 7 10 7a17.6 17.6 0 0 1-3.2 3.9M6.6 6.6C3.8 8.4 2 12 2 12s4 7 10 7c1.8 0 3.4-.6 4.8-1.4" /><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2" /></Svg>
export const IconGear = (p: P) => (
  <Svg {...p}>
    <circle cx="12" cy="12" r="3" />
    <path d="M19 12a7 7 0 0 0-.1-1.2l2-1.6-2-3.4-2.4 1a7 7 0 0 0-2-1.2L14 3h-4l-.5 2.6a7 7 0 0 0-2 1.2l-2.4-1-2 3.4 2 1.6A7 7 0 0 0 5 12c0 .4 0 .8.1 1.2l-2 1.6 2 3.4 2.4-1a7 7 0 0 0 2 1.2L10 21h4l.5-2.6a7 7 0 0 0 2-1.2l2.4 1 2-3.4-2-1.6c.1-.4.1-.8.1-1.2z" />
  </Svg>
)
export const IconPhone = (p: P) => <Svg strokeWidth={2} {...p}><rect x="6" y="2" width="12" height="20" rx="3" /><path d="M11 18h2" /></Svg>
export const IconDownload = (p: P) => <Svg strokeWidth={2} {...p}><path d="M12 3v12" /><path d="m7 10 5 5 5-5" /><path d="M5 21h14" /></Svg>
export const IconFilter = (p: P) => <Svg strokeWidth={2.4} {...p}><path d="M4 6h16M7 12h10M10 18h4" /></Svg>
export const IconWifiOff = (p: P) => (
  <Svg strokeWidth={2} {...p}>
    <path d="M2 8.5a15 15 0 0 1 20 0" />
    <path d="M5.5 12a10 10 0 0 1 13 0" />
    <path d="M9 15.5a5 5 0 0 1 6 0" />
    <circle cx="12" cy="19" r="1" />
    <path d="m3 3 18 18" stroke="var(--color-err)" />
  </Svg>
)
export const IconClose = (p: P) => <Svg strokeWidth={2.4} {...p}><path d="M6 6l12 12M18 6 6 18" /></Svg>
export const IconChevronLeft = (p: P) => <Svg strokeWidth={2.4} {...p}><path d="m15 5-7 7 7 7" /></Svg>
export const IconChevronRight = (p: P) => <Svg strokeWidth={2.4} {...p}><path d="m9 5 7 7-7 7" /></Svg>
export const IconMore = (p: P) => <Svg strokeWidth={3} {...p}><path d="M5 12h.01M12 12h.01M19 12h.01" /></Svg>
export const IconRefresh = (p: P) => <Svg strokeWidth={2.2} {...p}><path d="M20 11a8 8 0 1 0-2.3 5.7" /><path d="M20 4v7h-7" /></Svg>
export const IconHistory = (p: P) => <Svg strokeWidth={2.2} {...p}><path d="M3 12a9 9 0 1 0 3-6.7" /><path d="M3 4v5h5" /></Svg>
export const IconArrowRight = (p: P) => <Svg strokeWidth={2.4} {...p}><path d="M5 12h14M13 6l6 6-6 6" /></Svg>
export const IconArrowUp = (p: P) => <Svg strokeWidth={2.4} {...p}><path d="M12 19V5M6 11l6-6 6 6" /></Svg>
export const IconCopy = (p: P) => <Svg strokeWidth={2} {...p}><rect x="9" y="9" width="12" height="12" rx="2" /><path d="M5 15V5a2 2 0 0 1 2-2h10" /></Svg>
export const IconPlus = (p: P) => <Svg strokeWidth={2.6} {...p}><path d="M12 5v14M5 12h14" /></Svg>
export const IconMinus = (p: P) => <Svg strokeWidth={2.6} {...p}><path d="M5 12h14" /></Svg>
export const IconTablet = (p: P) => <Svg strokeWidth={2} {...p}><rect x="4" y="3" width="16" height="18" rx="2.5" /><path d="M11 18h2" /></Svg>
export const IconLaptop = (p: P) => <Svg strokeWidth={2} {...p}><rect x="4" y="5" width="16" height="11" rx="1.5" /><path d="M2 19h20" /></Svg>
export const IconTv = (p: P) => <Svg strokeWidth={2} {...p}><rect x="3" y="5" width="18" height="12" rx="2" /><path d="M8 21h8M12 17v4" /></Svg>
