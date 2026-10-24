-- Supabase PostgreSQL schema for the Attendance Checker.
-- Run this once in Supabase SQL Editor.
-- The PHP application also creates these tables automatically on first connection.

create table if not exists public.users (
    telegram_id text primary key,
    chat_id text not null,
    username text,
    first_name text,
    first_seen bigint not null,
    last_seen bigint not null,
    is_active boolean not null default true
);

create table if not exists public.attendance_requests (
    id bigserial primary key,
    telegram_id text not null references public.users(telegram_id) on delete cascade,
    roll_number text not null,
    status text not null,
    attendance_result text,
    requested_at bigint not null,
    check_count integer not null default 1,
    count_today integer not null default 1,
    count_date date not null default CURRENT_DATE,
    unique (telegram_id, roll_number)
);

create table if not exists public.suspended_roll_numbers (
    roll_number text primary key,
    reason text,
    suspended_by text,
    suspended_at bigint not null
);

create table if not exists public.broadcasts (
    id bigserial primary key,
    message text not null,
    sent_count integer not null default 0,
    failed_count integer not null default 0,
    created_at bigint not null
);

create table if not exists public.bot_settings (
    setting_key text primary key,
    setting_value text,
    updated_at bigint not null
);

create index if not exists idx_users_last_seen on public.users(last_seen);
create index if not exists idx_users_active on public.users(is_active);
create index if not exists idx_requests_telegram on public.attendance_requests(telegram_id);
create index if not exists idx_requests_requested_at on public.attendance_requests(requested_at);
create index if not exists idx_requests_roll on public.attendance_requests(roll_number);
create index if not exists idx_requests_check_count on public.attendance_requests(check_count desc);
create index if not exists idx_suspended_at on public.suspended_roll_numbers(suspended_at);

-- This database is accessed server-side by Render PHP using DATABASE_URL.
-- Do not put DATABASE_URL into frontend code or GitHub.


create table if not exists public.pending_broadcasts (
    token_hash text primary key,
    admin_chat_id text not null,
    message text not null,
    created_at bigint not null,
    expires_at bigint not null
);
