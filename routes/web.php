<?php

use App\Http\Controllers\AcademicController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\CollaborationController;
use App\Http\Controllers\PeopleController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:3,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update');
});

Route::middleware(['auth', 'account.active'])->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/verify-email', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware('signed')->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])->middleware('throttle:6,1')->name('verification.send');
    Route::get('/confirm-password', [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
    Route::post('/confirm-password', [ConfirmablePasswordController::class, 'store'])->name('password.confirm.store');
});

Route::middleware(['auth', 'account.active', 'verified'])->group(function () {
    Route::get('/', fn () => redirect()->route('dashboard'));
    Route::get('/dashboard', fn () => Inertia::render('Dashboard'))->name('dashboard');
    Route::resource('users', UserController::class)->except('show');
    Route::patch('/users/{user}/status', [UserController::class, 'status'])->name('users.status');
    Route::patch('/users/{user}/role', [UserController::class, 'role'])->middleware('password.confirm')->name('users.role');
    Route::post('/users/{user}/verification', [UserController::class, 'resendVerification'])->name('users.verification');
    Route::get('/academics', [AcademicController::class, 'index'])->name('academics.index');
    Route::post('/academic-years', [AcademicController::class, 'storeYear'])->name('academic-years.store');
    Route::patch('/academic-years/{academicYear}', [AcademicController::class, 'updateYear'])->name('academic-years.update');
    Route::post('/grade-levels', [AcademicController::class, 'storeGrade'])->name('grade-levels.store');
    Route::patch('/grade-levels/{gradeLevel}', [AcademicController::class, 'updateGrade'])->name('grade-levels.update');
    Route::post('/subjects', [AcademicController::class, 'storeSubject'])->name('subjects.store');
    Route::patch('/subjects/{subject}', [AcademicController::class, 'updateSubject'])->name('subjects.update');
    Route::post('/school-classes', [AcademicController::class, 'storeClass'])->name('school-classes.store');
    Route::patch('/school-classes/{schoolClass}', [AcademicController::class, 'updateClass'])->name('school-classes.update');
    Route::put('/school-classes/{schoolClass}/subjects', [AcademicController::class, 'syncSubjects'])->name('school-classes.subjects');
    Route::get('/people', [PeopleController::class, 'index'])->name('people.index');
    Route::post('/teacher-profiles', [PeopleController::class, 'storeTeacher'])->name('teacher-profiles.store');
    Route::patch('/teacher-profiles/{teacherProfile}', [PeopleController::class, 'updateTeacher'])->name('teacher-profiles.update');
    Route::post('/student-profiles', [PeopleController::class, 'storeStudent'])->name('student-profiles.store');
    Route::patch('/student-profiles/{studentProfile}', [PeopleController::class, 'updateStudent'])->name('student-profiles.update');
    Route::post('/student-profiles/{studentProfile}/enrollments', [PeopleController::class, 'enroll'])->name('enrollments.store');
    Route::patch('/enrollments/{enrollment}/end', [PeopleController::class, 'end'])->name('enrollments.end');
    Route::post('/enrollments/{enrollment}/transfer', [PeopleController::class, 'transfer'])->name('enrollments.transfer');
    Route::post('/school-classes/{schoolClass}/teacher', [PeopleController::class, 'assignClass'])->name('teacher-class-assignments.store');
    Route::post('/class-subjects/{classSubject}/teacher', [PeopleController::class, 'assignSubject'])->name('teacher-class-subject-assignments.store');
    Route::get('/collaboration', [CollaborationController::class, 'index'])->name('collaboration.index');
    Route::get('/collaboration/classes/{schoolClass}', [CollaborationController::class, 'workspace'])->name('collaboration.classes.show');
    Route::get('/collaboration/classes/{schoolClass}/channels/{channel}', [CollaborationController::class, 'workspace'])->name('collaboration.channels.show');
    Route::post('/collaboration/classes/{schoolClass}/channels', [ChannelController::class, 'store'])->name('collaboration.channels.store');
    Route::patch('/collaboration/classes/{schoolClass}/channels/{channel}', [ChannelController::class, 'update'])->name('collaboration.channels.update');
    Route::patch('/collaboration/classes/{schoolClass}/channels/{channel}/archive', [ChannelController::class, 'archive'])->name('collaboration.channels.archive');
    Route::patch('/collaboration/classes/{schoolClass}/channels/{channel}/restore', [ChannelController::class, 'restore'])->name('collaboration.channels.restore');
    Route::get('/collaboration/classes/{schoolClass}/channels/{channel}/announcements/create', [AnnouncementController::class, 'create'])->name('collaboration.announcements.create');
    Route::post('/collaboration/classes/{schoolClass}/channels/{channel}/announcements', [AnnouncementController::class, 'store'])->name('collaboration.announcements.store');
    Route::get('/collaboration/classes/{schoolClass}/channels/{channel}/announcements/{announcement}/edit', [AnnouncementController::class, 'edit'])->name('collaboration.announcements.edit');
    Route::patch('/collaboration/classes/{schoolClass}/channels/{channel}/announcements/{announcement}', [AnnouncementController::class, 'update'])->name('collaboration.announcements.update');
    Route::patch('/collaboration/classes/{schoolClass}/channels/{channel}/announcements/{announcement}/schedule', [AnnouncementController::class, 'schedule'])->name('collaboration.announcements.schedule');
    Route::patch('/collaboration/classes/{schoolClass}/channels/{channel}/announcements/{announcement}/publish', [AnnouncementController::class, 'publish'])->name('collaboration.announcements.publish');
    Route::patch('/collaboration/classes/{schoolClass}/channels/{channel}/announcements/{announcement}/archive', [AnnouncementController::class, 'archive'])->name('collaboration.announcements.archive');
    Route::patch('/collaboration/classes/{schoolClass}/channels/{channel}/announcements/{announcement}/restore', [AnnouncementController::class, 'restore'])->name('collaboration.announcements.restore');
});
