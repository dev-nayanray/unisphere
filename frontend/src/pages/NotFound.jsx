import { Link } from 'react-router-dom'

export default function NotFound() {
  return (
    <div className="mx-auto mt-16 max-w-md text-center">
      <p className="text-6xl font-bold text-indigo-600">404</p>
      <h1 className="mt-3 text-xl font-semibold">Page not found</h1>
      <p className="mt-2 text-sm text-slate-500">The page you are looking for doesn't exist or has moved.</p>
      <Link to="/" className="btn-primary mt-6">Back to dashboard</Link>
    </div>
  )
}
