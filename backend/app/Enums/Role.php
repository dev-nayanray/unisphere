<?php

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Registrar = 'registrar';
    case DepartmentHead = 'department_head';
    case Teacher = 'teacher';
    case Accounts = 'accounts';
    case Student = 'student';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Registrar => 'Registrar',
            self::DepartmentHead => 'Department Head',
            self::Teacher => 'Teacher',
            self::Accounts => 'Accounts Officer',
            self::Student => 'Student',
        };
    }

    /** Roles that may manage the whole university structure. */
    public static function admins(): array
    {
        return [self::SuperAdmin->value, self::Registrar->value];
    }

    /** Roles that teach or supervise teaching. */
    public static function faculty(): array
    {
        return [self::Teacher->value, self::DepartmentHead->value];
    }
}
