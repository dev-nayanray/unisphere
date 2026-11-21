import { Component } from 'react'

/** Catches render errors so one broken page doesn't blank the whole app. */
export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props)
    this.state = { error: null }
  }

  static getDerivedStateFromError(error) {
    return { error }
  }

  componentDidCatch(error, info) {
    console.error('UniSphere UI error:', error, info.componentStack)
  }

  render() {
    if (!this.state.error) return this.props.children

    return (
      <div className="mx-auto mt-16 max-w-md rounded-xl border border-rose-200 bg-white p-8 text-center shadow-sm">
        <p className="text-4xl">⚠️</p>
        <h1 className="mt-3 text-lg font-semibold">Something went wrong on this page</h1>
        <p className="mt-2 text-sm text-slate-500">{this.state.error.message}</p>
        <div className="mt-5 flex justify-center gap-2">
          <button className="btn-ghost" onClick={() => this.setState({ error: null })}>Try again</button>
          <a className="btn-primary" href="/">Go to dashboard</a>
        </div>
      </div>
    )
  }
}
