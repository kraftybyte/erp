<div class="cu-command-group">
    <div class="cu-command-group-label">Navigation</div>
    <a href="/admin" class="cu-command-item" data-label="Dashboard">
        <div class="cu-command-item-icon">@include('clickup-icons::home')</div>
        <div class="cu-command-item-text">
            <div class="cu-command-item-title">Dashboard</div>
            <div class="cu-command-item-desc">Go to dashboard</div>
        </div>
    </a>
    <a href="/admin/projects" class="cu-command-item" data-label="Projects">
        <div class="cu-command-item-icon">@include('clickup-icons::folder')</div>
        <div class="cu-command-item-text">
            <div class="cu-command-item-title">Projects</div>
            <div class="cu-command-item-desc">View all projects</div>
        </div>
    </a>
    <a href="/admin/clients" class="cu-command-item" data-label="Clients">
        <div class="cu-command-item-icon">@include('clickup-icons::users')</div>
        <div class="cu-command-item-text">
            <div class="cu-command-item-title">Clients</div>
            <div class="cu-command-item-desc">Manage clients</div>
        </div>
    </a>
    <a href="/admin/expenses" class="cu-command-item" data-label="Expenses">
        <div class="cu-command-item-icon">@include('clickup-icons::dollar')</div>
        <div class="cu-command-item-text">
            <div class="cu-command-item-title">Expenses</div>
            <div class="cu-command-item-desc">View expenses</div>
        </div>
    </a>
</div>

<div class="cu-command-group">
    <div class="cu-command-group-label">Quick Actions</div>
    <a href="/admin/tasks/create" class="cu-command-item" data-label="Create Task">
        <div class="cu-command-item-icon">@include('clickup-icons::plus')</div>
        <div class="cu-command-item-text">
            <div class="cu-command-item-title">Create Task</div>
            <div class="cu-command-item-desc">Create new task</div>
        </div>
        <span class="cu-command-item-shortcut">⌘N</span>
    </a>
    <a href="/admin/projects/create" class="cu-command-item" data-label="Create Project">
        <div class="cu-command-item-icon">@include('clickup-icons::plus')</div>
        <div class="cu-command-item-text">
            <div class="cu-command-item-title">Create Project</div>
            <div class="cu-command-item-desc">Create new project</div>
        </div>
    </a>
    <a href="/admin/expenses/create" class="cu-command-item" data-label="Submit Expense">
        <div class="cu-command-item-icon">@include('clickup-icons::dollar')</div>
        <div class="cu-command-item-text">
            <div class="cu-command-item-title">Submit Expense</div>
            <div class="cu-command-item-desc">Submit new expense</div>
        </div>
    </a>
    <a href="/admin/absences/create" class="cu-command-item" data-label="Request Leave">
        <div class="cu-command-item-icon">@include('clickup-icons::calendar')</div>
        <div class="cu-command-item-text">
            <div class="cu-command-item-title">Request Leave</div>
            <div class="cu-command-item-desc">Submit absence request</div>
        </div>
    </a>
</div>

<div class="cu-command-group">
    <div class="cu-command-group-label">Settings</div>
    <a href="/admin/settings" class="cu-command-item" data-label="Settings">
        <div class="cu-command-item-icon">@include('clickup-icons::settings')</div>
        <div class="cu-command-item-text">
            <div class="cu-command-item-title">Settings</div>
            <div class="cu-command-item-desc">App settings</div>
        </div>
    </a>
    <a href="/admin/pwa-settings" class="cu-command-item" data-label="PWA">
        <div class="cu-command-item-icon">@include('clickup-icons::bell')</div>
        <div class="cu-command-item-text">
            <div class="cu-command-item-title">PWA Settings</div>
            <div class="cu-command-item-desc">Push notifications</div>
        </div>
    </a>
</div>