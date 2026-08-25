import React from 'react';
import { Link, usePage } from '@inertiajs/react';

export default function AuthenticatedLayout({ children }) {
    const { auth, flash } = usePage().props;
    const canViewUsers = auth.permissions.includes('users.view');
    return <div className="min-h-screen"><header className="border-b bg-white"><div className="mx-auto flex max-w-7xl items-center gap-6 px-6 py-4"><Link href="/dashboard" className="font-bold">EDWAY School</Link>{canViewUsers && <Link href="/users">Users</Link>}{auth.permissions.includes('academic-years.view')&&<Link href="/academics">Academics</Link>}{auth.permissions.includes('students.view')&&<Link href="/people">People</Link>}<span className="ml-auto text-sm">{auth.user.name} · {auth.roles.join(', ')}</span><Link href="/logout" method="post" as="button" className="text-sm text-red-600">Logout</Link></div></header><main className="mx-auto max-w-7xl p-6">{flash.success && <div className="mb-4 rounded bg-green-100 p-3 text-green-800">{flash.success}</div>}{children}</main></div>;
}
