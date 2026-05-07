import { createRouter, createWebHistory } from 'vue-router';
import { normalizeAuthUser } from '../support/authUser';

const routes = [
  { path: '/login', component: () => import('../pages/Login.vue'), meta: { guest: true } },
  { path: '/', redirect: '/dashboard' },
  { path: '/dashboard', component: () => import('../pages/Dashboard.vue'), meta: { requiresAuth: true } },
  { path: '/transactions', component: () => import('../pages/Transactions.vue'), meta: { requiresAuth: true } },
  { path: '/funds', component: () => import('../pages/Funds.vue'), meta: { requiresAuth: true } },
  { path: '/closeout-rules', component: () => import('../pages/CloseoutRules.vue'), meta: { requiresAuth: true } },
  { path: '/debts', component: () => import('../pages/Debts.vue'), meta: { requiresAuth: true } },
  { path: '/categories', component: () => import('../pages/Categories.vue'), meta: { requiresAuth: true } },
  { path: '/my-family', component: () => import('../pages/MyFamily.vue'), meta: { requiresAuth: true } },
  { path: '/month-summary/:yearMonth', component: () => import('../pages/MonthSummary.vue'), meta: { requiresAuth: true } },
  { path: '/admin/users', component: () => import('../pages/admin/Users.vue'), meta: { requiresAuth: true, adminOnly: true } },
  { path: '/admin/families', component: () => import('../pages/admin/Families.vue'), meta: { requiresAuth: true, adminOnly: true } },
  { path: '/admin/categories', component: () => import('../pages/admin/Categories.vue'), meta: { requiresAuth: true, adminOnly: true } },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior() {
    return { top: 0, left: 0 };
  },
});

router.beforeEach((to, from, next) => {
  const userJson = localStorage.getItem('user');
  const user = userJson ? normalizeAuthUser(JSON.parse(userJson)) : null;

  if (to.meta.requiresAuth && !user) {
    next('/login');
  } else if (to.meta.guest && user) {
    next('/dashboard');
  } else if (to.meta.adminOnly && user && !user.isAdmin) {
    next('/dashboard');
  } else {
    next();
  }
});

export default router;
