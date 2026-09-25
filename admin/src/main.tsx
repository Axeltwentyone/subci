import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { RouterProvider, createBrowserRouter } from 'react-router'
import { ToastProvider } from '../../src/components/Toast'
import './admin.css'
import { Catalog, Audit } from './pages/Catalog'
import { Disputes } from './pages/Disputes'
import { Payments, Payouts, Requests } from './pages/Money'
import { Offers } from './pages/Offers'
import { Alerts } from './pages/Alerts'
import { Overview } from './pages/Overview'
import { UserPage, Users } from './pages/People'
import { Login, SessionProvider, Shell, TwoFactorSetup, useSession } from './shell'

const router = createBrowserRouter([
  {
    element: <Shell />,
    children: [
      { index: true, element: <Overview /> },
      { path: 'offers', element: <Offers /> },
      { path: 'payouts', element: <Payouts /> },
      { path: 'disputes', element: <Disputes /> },
      { path: 'requests', element: <Requests /> },
      { path: 'payments', element: <Payments /> },
      { path: 'users', element: <Users /> },
      { path: 'users/:id', element: <UserPage /> },
      { path: 'catalog', element: <Catalog /> },
      { path: 'audit', element: <Audit /> },
      { path: 'notifications', element: <Alerts /> },
      { path: '*', element: <Overview /> },
    ],
  },
])

function Gate() {
  const { admin, setup } = useSession()
  if (!admin) return <Login />
  if (setup) return <TwoFactorSetup />
  return <RouterProvider router={router} />
}

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <ToastProvider>
      <SessionProvider>
        <Gate />
      </SessionProvider>
    </ToastProvider>
  </StrictMode>,
)
