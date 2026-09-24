import { Navigate, Outlet, RouterProvider, ScrollRestoration, createBrowserRouter } from 'react-router'
import { AppShell } from './components/AppShell'
import { UpdateToast } from './components/pwa'
import { ToastProvider } from './components/Toast'
import { StoreProvider, useStore } from './lib/store'
import { Splash } from './screens/Splash'
import { Home } from './screens/Home'

/* Shell + Accueil dans le bundle initial ; le reste est chargé à la demande
   (et précaché par le service worker pour le hors-ligne). */
const account = () => import('./screens/account')
const discover = () => import('./screens/discover')
const entry = () => import('./screens/entry')
const host = () => import('./screens/host')
const manage = () => import('./screens/manage')
const purchase = () => import('./screens/purchase')

function Root() {
  return (
    <>
      <ScrollRestoration />
      <UpdateToast />
      <Outlet />
    </>
  )
}

/** Écrans qui demandent une session : sinon, retour au login. */
function RequireAuth() {
  const { state } = useStore()
  if (!state.user) return <Navigate to={state.onboarded ? '/login' : '/welcome'} replace />
  // Compte créé mais prénom / nom pas encore renseignés.
  if (!state.user.firstName || !state.user.lastName) return <Navigate to="/bienvenue" replace />
  return <Outlet />
}

function RequireSession() {
  const { state } = useStore()
  return state.user ? <Outlet /> : <Navigate to="/login" replace />
}

const router = createBrowserRouter([
  {
    element: <Root />,
    children: [
      { path: '/', element: <Splash /> },
      { path: '/welcome', lazy: () => entry().then((m) => ({ Component: m.Onboarding })) },
      { path: '/login', lazy: () => entry().then((m) => ({ Component: m.Login })) },
      {
        element: <RequireSession />,
        children: [{ path: '/bienvenue', lazy: () => entry().then((m) => ({ Component: m.NameSetup })) }],
      },
      {
        element: <RequireAuth />,
        children: [
          {
            element: <AppShell />,
            children: [
              { path: '/home', element: <Home /> },
              { path: '/explore', lazy: () => discover().then((m) => ({ Component: m.Explore })) },
              { path: '/subs', lazy: () => manage().then((m) => ({ Component: m.MySubs })) },
              { path: '/activity', lazy: () => account().then((m) => ({ Component: m.Activity })) },
              { path: '/profile', lazy: () => account().then((m) => ({ Component: m.Profile })) },
            ],
          },
          // Plein écran : nav masquée (recherche, produit, checkout, détail…)
          { path: '/explore/search', lazy: () => discover().then((m) => ({ Component: m.Search })) },
          { path: '/service/:id', lazy: () => discover().then((m) => ({ Component: m.ServicePage })) },
          { path: '/checkout/:id', lazy: () => purchase().then((m) => ({ Component: m.Checkout })) },
          { path: '/pay/:ref', lazy: () => purchase().then((m) => ({ Component: m.Paying })) },
          { path: '/success/:ref', lazy: () => purchase().then((m) => ({ Component: m.Success })) },
          { path: '/subs/:id', lazy: () => manage().then((m) => ({ Component: m.SubDetail })) },
          { path: '/settings', lazy: () => account().then((m) => ({ Component: m.SettingsScreen })) },
          { path: '/host', lazy: () => host().then((m) => ({ Component: m.HostPitch })) },
          { path: '/host/new', lazy: () => host().then((m) => ({ Component: m.HostSetup })) },
          { path: '/host/offers/:id', lazy: () => host().then((m) => ({ Component: m.ManageOffer })) },
        ],
      },
      { path: '*', lazy: () => discover().then((m) => ({ Component: m.NotFound })) },
    ],
  },
])

export default function App() {
  return (
    <StoreProvider>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </StoreProvider>
  )
}
