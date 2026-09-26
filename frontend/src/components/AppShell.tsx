import { NavLink, Outlet } from 'react-router'
import { SAND, useTopColor } from '../lib/hooks'
import { useUnread } from '../lib/store'
import { IconBell, IconHome, IconSearch, IconSubs, IconUser } from './icons'
import { OfflineBanner } from './OfflineBanner'
import { LogoMark, Wordmark, cx } from './ui'

const TABS = [
  { to: '/home', label: 'Accueil', Icon: IconHome },
  { to: '/explore', label: 'Explorer', Icon: IconSearch },
  { to: '/subs', label: 'Mes abos', Icon: IconSubs },
  { to: '/activity', label: 'Activité', Icon: IconBell },
  { to: '/profile', label: 'Profil', Icon: IconUser },
] as const

function CountBadge({ n, className }: { n: number; className?: string }) {
  if (!n) return null
  return (
    <span className={cx('min-w-4 rounded-lg bg-brand px-1 text-center text-[10px] leading-4 font-extrabold text-ink', className)} aria-label={`${n} non lues`}>
      {n}
    </span>
  )
}

/**
 * Mêmes 5 destinations partout : bottom nav (mobile),
 * rail (≥ 768), sidebar (≥ 1200).
 */
export function AppShell() {
  useTopColor(SAND)
  const unread = useUnread()
  return (
    <div className="min-h-dvh bg-sand md:grid md:grid-cols-[88px_1fr] desk:grid-cols-[248px_1fr]">
      {/* Rail / sidebar */}
      <nav aria-label="Navigation principale" className="sticky top-0 hidden h-dvh flex-col border-r border-line bg-white md:flex">
        <div className="flex flex-col items-center gap-[18px] py-6 desk:items-stretch desk:gap-1.5 desk:px-[18px] desk:py-7">
          <span className="desk:hidden">
            <LogoMark size={44} />
          </span>
          <Wordmark className="hidden px-3 pb-6 text-[26px] desk:block" />
          <span className="h-3 desk:hidden" />
          {TABS.map(({ to, label, Icon }) => (
            <NavLink key={to} to={to} replace aria-label={label} className="group relative">
              {({ isActive }) => (
                <>
                  {/* rail */}
                  <span className={cx('grid h-8 w-14 place-items-center rounded-2xl desk:hidden', isActive ? 'bg-ink text-brand' : 'text-subtle')}>
                    <Icon />
                  </span>
                  {to === '/activity' && <CountBadge n={unread} className="absolute -top-1 right-1 desk:hidden" />}
                  {/* sidebar */}
                  <span
                    className={cx(
                      'hidden h-12 items-center gap-3 rounded-[14px] px-3.5 text-[15px] font-bold desk:flex',
                      isActive ? 'bg-ink text-white' : 'text-muted hover:bg-sand',
                    )}
                  >
                    <span className={cx('flex', isActive && 'text-brand')}>
                      <Icon size={20} />
                    </span>
                    {label}
                    {to === '/activity' && <CountBadge n={unread} className="ml-auto h-5 min-w-5 rounded-[10px] text-[11px] leading-5" />}
                  </span>
                </>
              )}
            </NavLink>
          ))}
        </div>
      </nav>

      <div className="min-w-0">
        <OfflineBanner />
        <main className="pt-safe pb-[calc(env(safe-area-inset-bottom)+96px)] md:pb-10">
          <Outlet />
        </main>
      </div>

      {/* Bottom nav (mobile). replace : changer d'onglet n'ajoute rien à l'historique,
          le geste « retour » du téléphone ne fait donc pas défiler les onglets. */}
      <nav
        aria-label="Navigation principale"
        className="fixed inset-x-0 bottom-0 z-40 grid grid-cols-5 border-t border-line bg-white/96 px-1.5 pt-2 pb-[calc(env(safe-area-inset-bottom)+14px)] backdrop-blur md:hidden"
      >
        {TABS.map(({ to, label, Icon }) => (
          <NavLink key={to} to={to} replace className="pressable relative flex flex-col items-center gap-1 text-[11px] font-bold">
            {({ isActive }) => (
              <>
                <span className={cx('grid h-8 w-14 place-items-center rounded-2xl transition-colors duration-150', isActive ? 'bg-ink text-brand' : 'text-subtle')}>
                  <Icon />
                </span>
                <span className={isActive ? 'text-ink' : 'text-subtle'}>{label}</span>
                {to === '/activity' && <CountBadge n={unread} className="absolute top-0.5 right-[calc(50%-26px)]" />}
              </>
            )}
          </NavLink>
        ))}
      </nav>
    </div>
  )
}
