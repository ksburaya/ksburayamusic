import datetime
import logging
import random

from telegram import InlineKeyboardButton, InlineKeyboardMarkup, Update, WebAppInfo
from telegram.ext import (
    Application,
    CallbackQueryHandler,
    ContextTypes,
    ConversationHandler,
    MessageHandler,
    filters,
)

from api_client import bot_auth, get_reminder_users, journal_url, save_entry
from practices.base import BasePractice

logger = logging.getLogger(__name__)

# State constants — диапазон 200–299
SM_AWAIT_DESCRIPTION = 200

# Окно отправки: 09:00–22:00 МСК = 06:00–19:00 UTC
_WINDOW_START_UTC = 6
_WINDOW_END_UTC   = 19


# ── Payload builder ───────────────────────────────────────────────────────────

def _build_payload(data: dict) -> dict:
    return {
        'practiceId': 'sound-moment',
        'content': {
            'description': data.get('description', ''),
            'prompted_at': data.get('prompted_at', ''),
        },
    }


# ── Handlers ──────────────────────────────────────────────────────────────────

async def _sm_start(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    await query.answer()
    # Сохраняем время отправки уведомления как временную метку практики
    context.user_data['sm_prompted_at'] = query.message.date.isoformat()
    await context.bot.send_message(
        query.message.chat_id,
        'Напиши как можно больше деталей. Что это за звук? Почему именно он?',
    )
    return SM_AWAIT_DESCRIPTION


async def _sm_receive(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    user        = update.effective_user
    chat_id     = update.effective_chat.id
    description = update.message.text.strip()

    if not context.user_data.get('token'):
        auth = await bot_auth(user.id, user.full_name)
        if auth.get('token'):
            context.user_data['token'] = auth['token']

    payload = _build_payload({
        'description': description,
        'prompted_at': context.user_data.pop('sm_prompted_at', ''),
    })
    await save_entry(context.user_data.get('token', ''), payload)
    context.user_data['has_practices'] = True

    preview = description[:120] + ('…' if len(description) > 120 else '')
    url = await journal_url(user.id, user.full_name)
    await context.bot.send_message(
        chat_id,
        f'✦ <b>Сохранено!</b>\n\n<i>«{preview}»</i>',
        parse_mode='HTML',
        reply_markup=InlineKeyboardMarkup([[
            InlineKeyboardButton('📖 Открыть дневник', web_app=WebAppInfo(url=url)),
        ]]),
    )
    return ConversationHandler.END


# ── Scheduler ─────────────────────────────────────────────────────────────────

async def _send_to_all(context: ContextTypes.DEFAULT_TYPE) -> None:
    try:
        chat_ids = await get_reminder_users()
    except Exception as e:
        logger.error('sound-moment users fetch: %s', e)
        return

    for chat_id in chat_ids:
        try:
            await context.bot.send_message(
                chat_id,
                'Опиши звук, который прямо сейчас привлек твое внимание',
                reply_markup=InlineKeyboardMarkup([[
                    InlineKeyboardButton('Описать', callback_data='sound_moment:start'),
                ]]),
            )
        except Exception as e:
            logger.warning('sound-moment to %s: %s', chat_id, e)


async def _plan_for_today(app: Application) -> None:
    """Выбирает случайное время в окне и планирует run_once на сегодня."""
    now        = datetime.datetime.now(tz=datetime.timezone.utc)
    window_end = now.replace(hour=_WINDOW_END_UTC,   minute=0, second=0, microsecond=0)
    earliest   = now.replace(hour=_WINDOW_START_UTC, minute=0, second=0, microsecond=0)
    earliest   = max(earliest, now + datetime.timedelta(minutes=2))

    if earliest >= window_end:
        logger.info('sound-moment: окно закрыто, следующая отправка завтра')
        return

    seconds  = int((window_end - earliest).total_seconds())
    send_at  = earliest + datetime.timedelta(seconds=random.randint(0, seconds))
    app.job_queue.run_once(_send_to_all, when=send_at)
    logger.info('sound-moment запланирован на %s UTC', send_at.strftime('%H:%M'))


async def _daily_plan(context: ContextTypes.DEFAULT_TYPE) -> None:
    await _plan_for_today(context.application)


async def _startup_plan(context: ContextTypes.DEFAULT_TYPE) -> None:
    await _plan_for_today(context.application)


# ── Practice class ────────────────────────────────────────────────────────────

class SoundMomentPractice(BasePractice):
    practice_id = 'sound-moment'

    def get_entry_points(self):
        return [
            CallbackQueryHandler(_sm_start, pattern='^sound_moment:start$'),
        ]

    def get_states(self):
        return {
            SM_AWAIT_DESCRIPTION: [
                MessageHandler(filters.TEXT & ~filters.COMMAND, _sm_receive),
            ],
        }

    def register_jobs(self, app: Application) -> None:
        # Каждый день в начале окна планируем случайное время отправки
        app.job_queue.run_daily(
            _daily_plan,
            time=datetime.time(hour=_WINDOW_START_UTC, minute=0, tzinfo=datetime.timezone.utc),
        )
        # При старте бота — сразу планируем на сегодня (если окно ещё открыто)
        app.job_queue.run_once(_startup_plan, when=2)

    def build_entry(self, data: dict) -> dict:
        return _build_payload(data)
