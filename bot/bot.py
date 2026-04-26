import os
import logging
from typing import Optional
import requests
from dotenv import load_dotenv

load_dotenv()
from telegram import (
    Update, InlineKeyboardButton, InlineKeyboardMarkup,
    ReplyKeyboardMarkup, ReplyKeyboardRemove,
)
from telegram.ext import (
    Application, CommandHandler, MessageHandler,
    CallbackQueryHandler, ConversationHandler, ContextTypes, filters,
)

logging.basicConfig(
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    level=logging.INFO,
)
logger = logging.getLogger(__name__)

BOT_TOKEN   = os.environ['BOT_TOKEN']
WEBHOOK_URL = os.environ['WEBHOOK_URL']
PORT        = int(os.environ.get('PORT', 8080))
BOT_SECRET  = os.environ.get('BOT_SECRET', '')          # общий секрет с сервером
API_BASE    = 'https://ksburayamusic.ru/deeplistening/api'
JOURNAL_URL = 'https://t.me/deeplistening_journal_bot/Journal'

# ── FSM states ────────────────────────────────────────────────────────────────

(CHOOSE_DURATION, WAITING_DONE, ASK_PLACE,
 ASK_FRONT, ASK_RIGHT, ASK_BACK, ASK_LEFT,
 ASK_LIKED, ASK_DISLIKED, ASK_STORY, ASK_PHOTO) = range(11)

# ── Keyboards ─────────────────────────────────────────────────────────────────

def kb_durations() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup([[
        InlineKeyboardButton('5 мин',  callback_data='dur_5'),
        InlineKeyboardButton('10 мин', callback_data='dur_10'),
        InlineKeyboardButton('15 мин', callback_data='dur_15'),
        InlineKeyboardButton('20 мин', callback_data='dur_20'),
    ]])

def kb_done() -> ReplyKeyboardMarkup:
    return ReplyKeyboardMarkup([['✅ Готово']], resize_keyboard=True)

def kb_skip() -> ReplyKeyboardMarkup:
    return ReplyKeyboardMarkup(
        [['⏭ Пропустить']], resize_keyboard=True, one_time_keyboard=True
    )

def kb_new_practice() -> ReplyKeyboardMarkup:
    return ReplyKeyboardMarkup([['🎧 Начать практику']], resize_keyboard=True)

def kb_photo() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup([[
        InlineKeyboardButton('Да 📷',     callback_data='photo_yes'),
        InlineKeyboardButton('Пропустить', callback_data='photo_skip'),
    ]])

def kb_journal() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup([[
        InlineKeyboardButton('Открыть дневник 📖', url=JOURNAL_URL)
    ]])

def is_skip(text: str) -> bool:
    return text.strip().lower() in ('⏭ пропустить', 'пропустить', '/skip')

# ── API helpers ───────────────────────────────────────────────────────────────

def get_user_token(telegram_id: int, tg_name: str) -> Optional[str]:
    """POST /telegram-auth.php → {'token': '...'}
    Ожидает на сервере эндпоинт, принимающий telegram_id + bot_secret.
    """
    try:
        r = requests.post(
            f'{API_BASE}/telegram-auth.php',
            json={'telegram_id': telegram_id, 'name': tg_name, 'bot_secret': BOT_SECRET},
            timeout=10,
        )
        return r.json().get('token')
    except Exception as e:
        logger.error('get_user_token: %s', e)
        return None

def save_entry(telegram_id: int, tg_name: str, data: dict) -> bool:
    token = get_user_token(telegram_id, tg_name)
    if not token:
        logger.error('save_entry: не удалось получить токен для %d', telegram_id)
        return False
    try:
        payload = {
            'practice_id':  'listen-space',
            'duration_min': data.get('duration', 0),
            'place':        data.get('place', ''),
            'sounds': {
                'front': data.get('front', ''),
                'right': data.get('right', ''),
                'back':  data.get('back',  ''),
                'left':  data.get('left',  ''),
            },
            'liked':    data.get('liked',    ''),
            'disliked': data.get('disliked', ''),
            'notes':    data.get('story',    ''),
        }
        r = requests.post(
            f'{API_BASE}/entries.php',
            json=payload,
            headers={'Authorization': f'Bearer {token}'},
            timeout=10,
        )
        return r.ok
    except Exception as e:
        logger.error('save_entry: %s', e)
        return False

# ── Shared finish ─────────────────────────────────────────────────────────────

async def finish(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    user    = update.effective_user
    chat_id = update.effective_chat.id
    data    = context.user_data

    save_entry(user.id, user.full_name, data)

    d      = data.get('duration', '?')
    place  = data.get('place', '')
    sounds = []
    for key, arrow in [('front','⬆️'), ('right','➡️'), ('back','⬇️'), ('left','⬅️')]:
        v = data.get(key, '').strip()
        if v:
            sounds.append(f'  {arrow} {v}')

    text = '✦ <b>Запись сохранена!</b>\n\n'
    text += f'🎧 Слушание пространства · {d} мин'
    if place:
        text += f'\n📍 {place}'
    if sounds:
        text += '\n\n🔊 <b>Звуки:</b>\n' + '\n'.join(sounds)
    if data.get('liked'):
        text += f'\n\n❤️ {data["liked"]}'
    if data.get('disliked'):
        text += f'\n\n💔 {data["disliked"]}'
    if data.get('story'):
        text += f'\n\n📖 {data["story"]}'

    await context.bot.send_message(
        chat_id,
        text,
        parse_mode='HTML',
        reply_markup=kb_journal(),
    )
    await context.bot.send_message(
        chat_id,
        'Начать новую практику?',
        reply_markup=kb_new_practice(),
    )
    context.user_data.clear()
    return ConversationHandler.END

# ── Handlers ──────────────────────────────────────────────────────────────────

async def cmd_start(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    context.user_data.clear()
    await update.message.reply_text(
        '🎧 <b>Слушание пространства</b>\n\n'
        'Займите удобное положение, сделайте три глубоких вдоха и закройте глаза.\n'
        'Слушайте пространство без попытки оценить происходящее.\n\n'
        'Выберите длительность практики:',
        parse_mode='HTML',
        reply_markup=kb_durations(),
    )
    return CHOOSE_DURATION


async def on_choose_duration(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    await query.answer()
    duration = int(query.data.split('_')[1])
    context.user_data['duration'] = duration
    await query.edit_message_text(
        f'⏱ Установите таймер на <b>{duration} минут</b>.\n\n'
        'Позвольте звукам приходить и уходить, просто замечая их.',
        parse_mode='HTML',
    )
    await context.bot.send_message(
        query.message.chat_id,
        'Нажмите кнопку, когда практика завершится:',
        reply_markup=kb_done(),
    )
    return WAITING_DONE


async def on_waiting_done(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    await update.message.reply_text(
        '📍 <b>Где вы находились?</b>\n\n<i>Например: парк, квартира, залив, крыша…</i>',
        parse_mode='HTML',
        reply_markup=kb_skip(),
    )
    return ASK_PLACE


async def on_ask_place(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    text = update.message.text
    context.user_data['place'] = '' if is_skip(text) else text
    await update.message.reply_text(
        '🔊 Звуки <b>⬆️ СПЕРЕДИ</b>\n\n<i>Перечислите через запятую или нажмите «Пропустить»</i>',
        parse_mode='HTML',
        reply_markup=kb_skip(),
    )
    return ASK_FRONT


async def on_ask_front(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    text = update.message.text
    context.user_data['front'] = '' if is_skip(text) else text
    await update.message.reply_text(
        '🔊 Звуки <b>➡️ СПРАВА</b>\n\n<i>Перечислите через запятую или нажмите «Пропустить»</i>',
        parse_mode='HTML',
        reply_markup=kb_skip(),
    )
    return ASK_RIGHT


async def on_ask_right(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    text = update.message.text
    context.user_data['right'] = '' if is_skip(text) else text
    await update.message.reply_text(
        '🔊 Звуки <b>⬇️ СЗАДИ</b>\n\n<i>Перечислите через запятую или нажмите «Пропустить»</i>',
        parse_mode='HTML',
        reply_markup=kb_skip(),
    )
    return ASK_BACK


async def on_ask_back(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    text = update.message.text
    context.user_data['back'] = '' if is_skip(text) else text
    await update.message.reply_text(
        '🔊 Звуки <b>⬅️ СЛЕВА</b>\n\n<i>Перечислите через запятую или нажмите «Пропустить»</i>',
        parse_mode='HTML',
        reply_markup=kb_skip(),
    )
    return ASK_LEFT


async def on_ask_left(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    text = update.message.text
    context.user_data['left'] = '' if is_skip(text) else text
    await update.message.reply_text(
        '❤️ <b>Что понравилось или привлекло внимание?</b>\n\n<i>Звуки, качества, ощущения…</i>',
        parse_mode='HTML',
        reply_markup=kb_skip(),
    )
    return ASK_LIKED


async def on_ask_liked(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    text = update.message.text
    context.user_data['liked'] = '' if is_skip(text) else text
    await update.message.reply_text(
        '💔 <b>Что мешало или казалось лишним?</b>',
        parse_mode='HTML',
        reply_markup=kb_skip(),
    )
    return ASK_DISLIKED


async def on_ask_disliked(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    text = update.message.text
    context.user_data['disliked'] = '' if is_skip(text) else text
    await update.message.reply_text(
        '📖 <b>Опишите историю, которую вы услышали.</b>\n\n'
        '<i>Какой образ, сюжет или настроение возникло из звуков?</i>',
        parse_mode='HTML',
        reply_markup=kb_skip(),
    )
    return ASK_STORY


async def on_ask_story(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    text = update.message.text
    context.user_data['story'] = '' if is_skip(text) else text
    await update.message.reply_text(
        '📷 Хотите прикрепить фото места?',
        reply_markup=ReplyKeyboardRemove(),
    )
    await update.message.reply_text('Выберите:', reply_markup=kb_photo())
    return ASK_PHOTO


async def on_photo_yes(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    await query.answer()
    await query.edit_message_text('Пришлите фотографию:')
    return ASK_PHOTO


async def on_photo_skip(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    await update.callback_query.answer()
    return await finish(update, context)


async def on_receive_photo(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    context.user_data['photo_file_id'] = update.message.photo[-1].file_id
    await update.message.reply_text(
        '💾 Сохраните фото на телефон, если хотите оставить его у себя.',
        reply_markup=ReplyKeyboardRemove(),
    )
    return await finish(update, context)

# ── Main ──────────────────────────────────────────────────────────────────────

def main() -> None:
    app = Application.builder().token(BOT_TOKEN).build()

    conv = ConversationHandler(
        entry_points=[
            CommandHandler('start', cmd_start),
            MessageHandler(filters.Regex('^🎧 Начать практику$'), cmd_start),
        ],
        states={
            CHOOSE_DURATION: [CallbackQueryHandler(on_choose_duration, pattern='^dur_')],
            WAITING_DONE:    [MessageHandler(filters.TEXT & ~filters.COMMAND, on_waiting_done)],
            ASK_PLACE:       [MessageHandler(filters.TEXT & ~filters.COMMAND, on_ask_place)],
            ASK_FRONT:       [MessageHandler(filters.TEXT & ~filters.COMMAND, on_ask_front)],
            ASK_RIGHT:       [MessageHandler(filters.TEXT & ~filters.COMMAND, on_ask_right)],
            ASK_BACK:        [MessageHandler(filters.TEXT & ~filters.COMMAND, on_ask_back)],
            ASK_LEFT:        [MessageHandler(filters.TEXT & ~filters.COMMAND, on_ask_left)],
            ASK_LIKED:       [MessageHandler(filters.TEXT & ~filters.COMMAND, on_ask_liked)],
            ASK_DISLIKED:    [MessageHandler(filters.TEXT & ~filters.COMMAND, on_ask_disliked)],
            ASK_STORY:       [MessageHandler(filters.TEXT & ~filters.COMMAND, on_ask_story)],
            ASK_PHOTO: [
                CallbackQueryHandler(on_photo_yes,  pattern='^photo_yes$'),
                CallbackQueryHandler(on_photo_skip, pattern='^photo_skip$'),
                MessageHandler(filters.PHOTO, on_receive_photo),
            ],
        },
        fallbacks=[CommandHandler('start', cmd_start)],
        allow_reentry=True,
    )
    app.add_handler(conv)

    app.run_webhook(
        listen='0.0.0.0',
        port=PORT,
        url_path='webhook',
        webhook_url=f'{WEBHOOK_URL}/webhook',
    )


if __name__ == '__main__':
    main()
